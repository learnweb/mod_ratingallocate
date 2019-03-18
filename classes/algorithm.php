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

namespace mod_ratingallocate;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../locallib.php');

abstract class algorithm {

    public abstract function get_name();

    /**
     * Computes the distribution of students to choices based on the students ratings.
     * @param $choicerecords array[] array of all choices which are ratable in this ratingallocate.
     * @param $ratings array[] array of all relevant ratings.
     * @param $usercount int total number of raters in course.
     * @return array mapping of choice ids to array of user ids.
     */
    protected abstract function compute_distribution($choicerecords, $ratings, $usercount);

    /**
     * Entry-point for the \ratingallocate object to call a solver
     * @param \ratingallocate $ratingallocate
     */
    public function distribute_users(\ratingallocate $ratingallocate) {

        // Load data from database.
        $choicerecords = $ratingallocate->get_rateable_choices();
        $ratings = $ratingallocate->get_ratings_for_rateable_choices();

        // Randomize the order of the enrties to prevent advantages for early entry.
        shuffle($ratings);

        $usercount = count($ratingallocate->get_raters_in_course());

        $distributions = $this->compute_distribution($choicerecords, $ratings, $usercount);

        // Perform all allocation manipulation / inserts in one transaction.
        $transaction = $ratingallocate->db->start_delegated_transaction();

        $ratingallocate->clear_all_allocations();

        foreach ($distributions as $choiceid => $users) {
            foreach ($users as $userid) {
                $ratingallocate->add_allocation($choiceid, $userid);
            }
        }
        $transaction->allow_commit();
    }

    /**
     * Compute the 'satisfaction' functions that is to be maximized by adding the
     * ratings users gave to their allocated choices
     * @param array $ratings
     * @param array $distribution
     * @return integer
     */
    public static function compute_target_function($ratings, $distribution) {
        $functionvalue = 0;
        foreach ($distribution as $choiceid => $choice) {
            // In $choice ist jetzt ein array von userids.
            foreach ($choice as $userid) {
                // Jetzt das richtige Rating rausfinden.
                foreach ($ratings as $rating) {
                    if ($rating->userid == $userid && $rating->choiceid == $choiceid) {
                        $functionvalue += $rating->rating;
                        continue; // Aus der Such-Schleife raus und weitermachen.
                    }
                }

            }
        }
        return $functionvalue;
    }

    /**
     * @param string $name Subplugin name without 'raalgo_'-prefix.
     * @return algorithm Algorithm instance
     */
    public static function get_instance(string $name) {
        $possible = self::get_available_algorithms();
        if (array_key_exists($name, $possible)) {
            $classname = '\raalgo_' . $name . '\algorithm_impl';
            return new $classname();
        } else {
            throw new \coding_exception('Tried to instantiate algorithm that is not installed or available.');
        }
    }

    /**
     * Get the list of available algorithms
     * @return string[] of the form Name -> Displayname
     */
    public static function get_available_algorithms() {
        $algorithms = \core_plugin_manager::instance()->get_plugins_of_type('raalgo');
        $result = array();
        foreach($algorithms as $algo) {
            $result[$algo->name] = $algo->displayname;
        }
        return $result;
    }

}