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

namespace mod_presenterai\local\vision\denylist;

/**
 * The English word lists behind layers 2 and 3 of the visual feedback gate.
 *
 * Data, not lang strings (design 4.4). The gate looks a list up by the
 * language the feedback was written in, with no fallback to English: a
 * French summary checked against English words would pass every time and
 * look checked. Where no list exists, layers 2 and 3 are skipped and the
 * judge carries the gate alone, which is why visualsummaryjudge is on by
 * default everywhere.
 *
 * Matching is whole word, case insensitive and Unicode aware, and a term of
 * several words matches across any run of spaces. Word boundaries do not save
 * "glasses" from "your glass of water" in practice; the false positive rate
 * can only be learned from real output, which is what presenterai_gatelog is
 * for (design 5.3).
 *
 * "frame" is an observable and is deliberately not a deny term; "chair back",
 * "headrest" and "armrest" carry the assistive case instead (design 4.4, F15).
 * "hair" and "clothing" are hard terms, which is why the seed rubric no longer
 * scores habits involving them (design 12.2, F5).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class en {
    /**
     * Terms that reject a string outright, by category.
     *
     * The category is what the gate records as the rule, so a gatelog reader
     * can see which group fired without the list being the only clue.
     *
     * @return array category => list of terms
     */
    public static function hard(): array {
        return [
            'appearance' => [
                'appearance', 'attractive', 'handsome', 'pretty', 'beautiful', 'ugly', 'scruffy', 'tired-looking',
                'beard', 'bearded', 'moustache', 'mustache', 'stubble', 'sideburns', 'eyebrows', 'eyelashes',
                'hair', 'hairstyle', 'haircut', 'hairline', 'bald', 'balding', 'braids', 'dreadlocks', 'ponytail',
                'blonde', 'blond', 'brunette', 'redhead', 'grey-haired', 'gray-haired',
                'tattoo', 'tattoos', 'piercing', 'piercings', 'makeup', 'make-up', 'lipstick',
                'skin', 'complexion', 'freckles', 'wrinkles', 'acne', 'scar',
                'physique', 'overweight', 'underweight', 'fat', 'skinny', 'slim', 'heavyset', 'stocky',
                'muscular', 'chubby', 'obese', 'petite', 'tall', 'height', 'weight',
                'slouched', 'slouching', 'slumped', 'sloppy',
            ],
            'identity' => [
                'race', 'racial', 'ethnicity', 'ethnic', 'nationality', 'skin colour', 'skin color',
                'asian', 'african', 'hispanic', 'latino', 'latina', 'caucasian', 'arab',
                'young', 'younger', 'youthful', 'old', 'older', 'elderly', 'middle-aged', 'teenage', 'teenager',
                'man', 'woman', 'male', 'female', 'boy', 'girl', 'gender', 'feminine', 'masculine',
                'accent', 'native speaker', 'non-native',
                'religion', 'religious', 'muslim', 'christian', 'jewish', 'hindu', 'sikh', 'buddhist',
            ],
            'clothing' => [
                'clothing', 'clothes', 'outfit', 'dressed', 'wearing', 'wore',
                'shirt', 't-shirt', 'tshirt', 'hoodie', 'sweater', 'jumper', 'blouse', 'tie',
                'jacket', 'collar', 'sleeve', 'sleeves', 'jewellery', 'jewelry', 'necklace', 'earring', 'earrings',
                'bracelet', 'watch', 'glasses', 'spectacles', 'sunglasses',
                'hijab', 'headscarf', 'scarf', 'turban', 'kippah', 'yarmulke', 'sheitel', 'cap', 'hat', 'hood',
                'veil', 'niqab', 'burqa', 'dupatta', 'wrap', 'bonnet', 'patka', 'taqiyah', 'gele', 'durag', 'headwrap',
            ],
            'health' => [
                'health', 'healthy', 'unhealthy', 'ill', 'illness', 'sick', 'disease', 'disability', 'disabled',
                'impairment', 'impaired', 'blind', 'deaf', 'autism', 'autistic', 'adhd', 'anxiety', 'anxious',
                'depressed', 'depression', 'injury', 'injured', 'pregnant', 'pregnancy', 'paralysis', 'palsy',
                'stammer', 'stutter', 'twitch', 'tic', 'tics',
            ],
            'assistive' => [
                'wheelchair', 'chair back', 'headrest', 'armrest', 'cane', 'crutch', 'crutches', 'walker',
                'walking frame', 'prosthesis', 'prosthetic', 'brace', 'sling', 'splint', 'oxygen', 'tube',
                'hearing aid', 'cochlear', 'interpreter', 'signer', 'service dog', 'guide dog', 'tremor',
            ],
            'person' => [
                'someone', 'somebody', 'another person', 'other person', 'other people', 'anyone else',
                'person behind', 'passer-by', 'passerby',
            ],
            'character' => [
                'tense', 'nervous', 'nervously', 'shy', 'timid', 'insecure', 'awkward', 'confident', 'unconfident',
                'confidence', 'personality', 'introvert', 'introverted', 'extrovert', 'extroverted', 'lazy', 'bored',
                'uncomfortable', 'scared', 'afraid', 'frightened', 'sad', 'angry', 'upset', 'stressed',
            ],
        ];
    }

    /**
     * Setting words, which reject a sentence only when it carries no rubric anchor.
     *
     * "You drifted toward the wall as you spoke" is a fair comment on movement
     * and "the wall behind you was cluttered" is a comment on someone's home;
     * the anchor is what tells them apart.
     *
     * @return string[]
     */
    public static function soft(): array {
        return [
            'bedroom', 'kitchen', 'bed', 'poster', 'wall', 'curtain', 'curtains', 'plant', 'plants', 'pet', 'cat',
            'dog', 'child', 'children', 'baby', 'roommate', 'flatmate', 'messy', 'cluttered', 'untidy', 'room',
            'furniture', 'shelf', 'shelves', 'bookcase', 'background', 'house', 'home', 'apartment', 'flat',
        ];
    }

    /**
     * The things a learner can change, any one of which anchors a string to the rubric (layer 3).
     *
     * @return string[]
     */
    public static function observables(): array {
        return [
            'hand', 'hands', 'arm', 'arms', 'gesture', 'gestures', 'gesturing', 'gestured', 'posture', 'stance',
            'movement', 'movements', 'moved', 'moving', 'eye', 'eyes', 'eye contact', 'gaze', 'looking', 'looked at',
            'camera', 'lens', 'frame', 'frames', 'framing', 'framed', 'shot', 'light', 'lighting', 'lit',
            'shoulders', 'head', 'face', 'notes', 'screen',
        ];
    }
}
