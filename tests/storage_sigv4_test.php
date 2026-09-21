<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_presenterai;

use mod_presenterai\local\storage\s3_store;

/**
 * Pins the SigV4 presigner against AWS's own published worked example.
 *
 * A signer that is subtly wrong never fails here. It fails in production, and
 * it fails as a 403 carrying SignatureDoesNotMatch, which looks like a network
 * fault or a permissions fault and gets investigated as one. The published
 * example is the only input in this file whose expected output was not produced
 * by this code, which is what makes it evidence rather than a snapshot.
 *
 * Pure signing only, so no database and no site configuration: everything the
 * store needs is handed to it through the constructor overrides.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\storage\s3_store
 */
final class storage_sigv4_test extends \basic_testcase {
    /** @var string The signature AWS publishes for the worked example below. */
    private const AWS_EXAMPLE_SIGNATURE = 'aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404';

    /**
     * The inputs of AWS's published presigned-URL worked example.
     *
     * GET https://examplebucket.s3.amazonaws.com/test.txt in us-east-1 on
     * 2013-05-24 with X-Amz-Expires=86400. Nothing here may be adjusted to make
     * a test pass: change any of it and the expected signature is no longer the
     * one AWS published, and the pin stops proving anything.
     *
     * @return array Parameters for s3_store::presign_url().
     */
    private function aws_example_params(): array {
        return [
            'host' => 'examplebucket.s3.amazonaws.com',
            'region' => 'us-east-1',
            'service' => 's3',
            'accesskey' => 'AKIAIOSFODNN7EXAMPLE',
            'secretkey' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'method' => 'GET',
            'uri' => '/test.txt',
            'expires' => 86400,
            'timestamp' => gmmktime(0, 0, 0, 5, 24, 2013),
        ];
    }

    /**
     * The X-Amz-Signature value carried by a presigned URL.
     *
     * @param string $url A presigned URL.
     * @return string The hex signature, or the empty string if there is none.
     */
    private function signature_of(string $url): string {
        return preg_match('/X-Amz-Signature=([0-9a-f]+)/', $url, $matches) ? $matches[1] : '';
    }

    /**
     * The X-Amz-Date value carried by a presigned URL.
     *
     * Two URLs signed in different seconds have different signatures for a
     * reason that has nothing to do with what a test is asserting, so a test
     * comparing two signatures has to know whether it is comparing like with
     * like.
     *
     * @param string $url A presigned URL.
     * @return string The date stamp, or the empty string if there is none.
     */
    private function date_of(string $url): string {
        return preg_match('/X-Amz-Date=([0-9TZ]+)/', $url, $matches) ? $matches[1] : '';
    }

    /**
     * An s3_store configured entirely through its constructor.
     *
     * No get_config() call is reachable from the methods this file exercises,
     * which is what lets these tests be basic_testcase rather than paying for a
     * database reset.
     *
     * @return s3_store
     */
    private function store(): s3_store {
        return new s3_store([
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'bucket' => 'examplebucket',
            'region' => 'us-east-1',
            'prefix' => 'presenterai/',
            'endpoint' => '',
            'pathstyle' => '0',
        ]);
    }

    /**
     * The presigner reproduces AWS's published signature exactly.
     *
     * @return void
     */
    public function test_sigv4_matches_the_aws_published_example(): void {
        $url = s3_store::presign_url($this->aws_example_params());

        $this->assertSame(
            self::AWS_EXAMPLE_SIGNATURE,
            $this->signature_of($url),
            'The SigV4 presigner no longer agrees with AWS. Every presigned URL this plugin issues '
                . 'will be refused with SignatureDoesNotMatch, so no learner can upload a recording '
                . 'and no learner can play one back, and the only symptom is a 403 that reads like a '
                . 'bucket permissions problem.'
        );
    }

    /**
     * The query string carries every parameter S3 requires, spelled as S3 spells it.
     *
     * @return void
     */
    public function test_the_presigned_query_carries_the_required_parameters(): void {
        $url = s3_store::presign_url($this->aws_example_params());

        $this->assertStringContainsString(
            'X-Amz-Algorithm=AWS4-HMAC-SHA256',
            $url,
            'S3 rejects a presigned URL that does not name its algorithm.',
        );
        $this->assertStringContainsString(
            'X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request',
            $url,
            'The credential scope must be percent-encoded in the query string. Sending it raw makes S3 '
                . 'compute a different canonical request and refuse the URL.'
        );
        $this->assertStringContainsString(
            'X-Amz-Expires=86400',
            $url,
            'A presigned URL with no expiry is not a presigned URL S3 will accept.',
        );
        $this->assertStringContainsString(
            'X-Amz-SignedHeaders=host',
            $url,
            'S3 needs to be told which headers were signed or it cannot verify the signature.',
        );
    }

    /**
     * Key paths encode each segment and keep the slashes between them.
     *
     * @return void
     */
    public function test_encode_key_path_encodes_segments_and_keeps_slashes(): void {
        $this->assertSame(
            '/presenterai/2/5/abc.mp4',
            s3_store::encode_key_path('presenterai/2/5/abc.mp4'),
            'A key path must begin with one slash. Without it the canonical URI is wrong and every request is refused.'
        );
        $this->assertSame(
            '/presenterai/2/5/abc.mp4',
            s3_store::encode_key_path('/presenterai/2/5/abc.mp4'),
            'A key stored with a leading slash must sign to the same path as one stored without, or media written by '
                . 'one code path becomes unreadable by another.'
        );
        $this->assertSame(
            '/a%20b/c',
            s3_store::encode_key_path('a b/c'),
            'Encoding the slash separators would file the object under a single flat key containing %2F, and the '
                . 'ownership check, which matches on the prefix/courseid/userid path, would then reject the learner '
                . 'own media as foreign.'
        );
    }

    /**
     * A download name is signed, not appended.
     *
     * Pinned against the AWS example so both signatures come from fixed inputs
     * rather than from the clock. SigV4 hashes the canonical query string, so a
     * parameter added after signing produces a URL S3 answers with
     * SignatureDoesNotMatch.
     *
     * @return void
     */
    public function test_a_content_disposition_is_inside_the_signature(): void {
        $params = $this->aws_example_params();

        $plain = s3_store::presign_url($params);
        $download = s3_store::presign_url($params + [
            'extraquery' => ['response-content-disposition' => 'attachment; filename="talk.webm"'],
        ]);

        $this->assertSame(
            self::AWS_EXAMPLE_SIGNATURE,
            $this->signature_of($plain),
            'The control URL in this test is no longer the AWS worked example, so the comparison below proves nothing.'
        );
        $this->assertStringContainsString(
            'response-content-disposition=',
            $download,
            'Without this parameter the browser plays the recording inline instead of saving it, so the Download '
                . 'button does nothing a learner can find afterwards.'
        );
        $this->assertNotSame(
            $this->signature_of($plain),
            $this->signature_of($download),
            'response-content-disposition was appended to the finished URL rather than signed into the canonical '
                . 'query string. S3 answers such a URL with SignatureDoesNotMatch, so the learner clicks Download, '
                . 'receives an XML error page, and loses the recording when the retention clock deletes it.'
        );
    }

    /**
     * read_url() with a download name signs a different URL from read_url() without one.
     *
     * The test above pins the mechanism; this one pins the method a caller
     * actually reaches, because the two are only connected if read_url() passes
     * the disposition through extraquery rather than concatenating it.
     *
     * @return void
     */
    public function test_read_url_signs_the_download_name(): void {
        $store = $this->store();
        $key = 'presenterai/7/42/abcdefghijklmnopqrstuvwx.webm';

        // A URL is signed with time(), so two calls either side of a second
        // boundary differ for a reason this test is not about. Retry until both
        // fall in one second rather than assert on unlike inputs, which would
        // pass whether or not the feature works.
        $plain = '';
        $download = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $plain = $store->read_url($key, 900);
            $download = $store->read_url($key, 900, 'my talk.webm');
            if ($this->date_of($plain) === $this->date_of($download) && $this->date_of($plain) !== '') {
                break;
            }
        }
        $this->assertSame(
            $this->date_of($plain),
            $this->date_of($download),
            'Both URLs must be signed within the same second for the signature comparison below to mean anything.'
        );

        $this->assertStringContainsString(
            'response-content-disposition=attachment',
            rawurldecode($download),
            'read_url() dropped the download name, so the Download button serves the recording inline and the learner '
                . 'has no copy once the retention clock runs.'
        );
        $this->assertStringNotContainsString(
            'my talk.webm',
            rawurldecode($download),
            'The download name reaches a Content-Disposition header S3 sends back, and the learner influences it '
                . 'through the file they uploaded, so it must be reduced to safe characters rather than passed through.'
        );
        $this->assertNotSame(
            $this->signature_of($plain),
            $this->signature_of($download),
            'read_url() built the download URL by appending to the playback URL instead of signing the disposition. '
                . 'S3 refuses that URL with SignatureDoesNotMatch and the learner cannot download their recording.'
        );
    }

    /**
     * Every read URL refuses to be cached, not only downloads.
     *
     * A presigned URL is a bearer token. A shared proxy that cached one would
     * hand a learner's recording to whoever asked for the same URL next, and a
     * cached response does not reach S3 to have its expiry checked.
     *
     * @return void
     */
    public function test_every_read_url_signs_a_private_cache_control(): void {
        $url = $this->store()->read_url('presenterai/7/42/abcdefghijklmnopqrstuvwx.webm', 900);

        $this->assertStringContainsString(
            'response-cache-control=private%2C%20no-store',
            $url,
            'Without a signed private cache-control, an intermediate proxy may store a learner recording and serve '
                . 'it to the next person who requests that URL, long after the signature has expired.'
        );
    }

    /**
     * The signature covers the HTTP method, so a playback URL cannot be used to upload.
     *
     * @return void
     */
    public function test_the_method_is_part_of_the_signature(): void {
        $get = s3_store::presign_url($this->aws_example_params());
        $put = s3_store::presign_url(['method' => 'PUT'] + $this->aws_example_params());

        $this->assertNotSame(
            $this->signature_of($get),
            $this->signature_of($put),
            'The HTTP method is not being signed. A URL handed out for playback would then also accept a PUT, which '
                . 'lets anyone holding a playback link overwrite the learner recording it points at.'
        );
    }
}
