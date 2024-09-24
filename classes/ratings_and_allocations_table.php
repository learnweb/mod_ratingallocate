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

/**
 * @package    mod_ratingallocate
 * @copyright  2016 Janek Lasocki-Biczysko <j.lasocki-biczysko@intrallect.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ratingallocate;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

class ratings_and_allocations_table extends \table_sql {

    const CHOICE_COL = 'choice_';
    const EXPORT_CHOICE_ALLOC_SUFFIX = 'alloc';
    const EXPORT_CHOICE_TEXT_SUFFIX = 'text';

    private $choicenames = array();
    private $choicemax = array();
    private $choicesum = array();

    private $titles;

    private $shownames;

    /**
     * @var array Array of all groups being used in the restriction settings of the choices of this ratingallocate instance.
     */
    private $groupsofallchoices;

    /**
     * @var array Array of all group names assigned to the choices, with choice id as key.
     */
    private $groupnamesofchoices;

    /**
     * @var bool if true the table should show a column with the groups in this ratingallocate instance which the user belongs to.
     */
    private $showgroups;

    /**
     * @var bool if true the table should show a column with the teams of the teamvote grouping.
     */
    private $showteams;

    /**
     * @var bool if true the cells are rendered as radio buttons
     */
    private $writeable;

    /**
     * @var \ratingallocate
     */
    private $ratingallocate;

    /**
     * @var \mod_ratingallocate_renderer
     */
    private $renderer;

    public function __construct(\mod_ratingallocate_renderer $renderer, $titles, $ratingallocate,
            $action = ACTION_SHOW_RATINGS_AND_ALLOCATION_TABLE, $uniqueid = 'mod_ratingallocate_table', $downloadable = true) {
        parent::__construct($uniqueid);
        global $PAGE;
        $url = $PAGE->url;
        $url->params(array("action" => $action));
        $PAGE->set_url($url);
        $this->renderer = $renderer;
        $this->titles = $titles;
        $this->ratingallocate = $ratingallocate;
        $allgroupsofchoices = $this->ratingallocate->get_all_groups_of_choices();
        $this->groupsofallchoices = array_map(function($groupid) {
            return groups_get_group($groupid);
        }, $allgroupsofchoices);
        if ($downloadable && has_capability('mod/ratingallocate:export_ratings', $ratingallocate->get_context())) {
            $download = optional_param('download', '', PARAM_ALPHA);
            $this->is_downloading($download,
                $ratingallocate->ratingallocate->name . '-ratings_and_allocations',
                'ratings_and_allocations');
        }

        $this->shownames = true;
        // We only show the group column if at least one group is being used in at least one active restriction setting of a choice.
        $this->showgroups = !empty($allgroupsofchoices);
        $teamvote = $this->ratingallocate->get_teamvote_groups();
        $this->ratingallocate->delete_groups_for_usersnogroup($teamvote);
        $this->showteams = (bool) $teamvote;

    }

    /**
     * Setup this table with choices and filter options
     *
     * @param array $choices an array of choices
     * @param $hidenorating
     * @param $showallocnecessary
     */
    public function setup_table($choices, $hidenorating = null, $showallocnecessary = null, $groupselect = 0) {

        if (empty($this->baseurl)) {
            global $PAGE;
            $this->baseurl = $PAGE->url;
        }

        $allocationcounts = $this->ratingallocate->get_choices_with_allocationcount();

        // Store choice data, and sort by choice id.
        foreach ($choices as $choice) {
            $this->choicenames[$choice->id] = $choice->title;
            $this->choicemax[$choice->id] = $choice->maxsize;
            if ($allocationcounts[$choice->id]->usercount) {
                $this->choicesum[$choice->id] = $allocationcounts[$choice->id]->usercount;
            } else {
                $this->choicesum[$choice->id] = 0;
            }

        }

        ksort($this->choicenames);
        ksort($this->choicesum);

        // Prepare the table structure.
        $columns = [];
        $headers = [];

        if ($this->shownames) {
            if ($this->is_downloading()) {
                global $CFG;
                $additionalfields = explode(',', $CFG->ratingallocate_download_userfields);
                if (in_array('id', $additionalfields)) {
                    $columns[] = 'id';
                    $headers[] = 'ID';
                }
                if (in_array('username', $additionalfields)) {
                    $columns[] = 'username';
                    $headers[] = get_string('username');
                }
                if (in_array('idnumber', $additionalfields)) {
                    $columns[] = 'idnumber';
                    $headers[] = get_string('idnumber');
                }
                if (in_array('department', $additionalfields)) {
                    $columns[] = 'department';
                    $headers[] = get_string('department');
                }
                if (in_array('institution', $additionalfields)) {
                    $columns[] = 'institution';
                    $headers[] = get_string('institution');
                }
                $columns[] = 'firstname';
                $headers[] = get_string('firstname');
                $columns[] = 'lastname';
                $headers[] = get_string('lastname');
                global $COURSE;
                if (in_array('email', $additionalfields) &&
                        has_capability('moodle/course:useremail', $this->ratingallocate->get_context())) {
                    $columns[] = 'email';
                    $headers[] = get_string('email');
                }
                if ($this->showteams) {
                    $columns[] = 'teams';
                    $headers[] = get_string('teams', 'mod_ratingallocate');
                }
            } else {
                if ($this->showteams) {
                    $columns[] = 'teams';
                    $headers[] = get_string('teams', 'mod_ratingallocate');
                    $columns[] = 'teammembers';
                    $headers[] = get_string('allocations_table_users', RATINGALLOCATE_MOD_NAME);
                } else {
                    $columns[] = 'fullname';
                    $headers[] = get_string('allocations_table_users', RATINGALLOCATE_MOD_NAME);
                }
            }
            // We only want to add a group column, if at least one choice has an active group restriction.
            if ($this->showgroups) {
                $columns[] = 'groups';
                $headers[] = get_string('groups');
                // Prepare group names of choices.
                $this->groupnamesofchoices = [];
                foreach ($choices as $choice) {
                    $this->groupnamesofchoices[$choice->id] = array_map(fn($group) => groups_get_group_name($group->id),
                            $this->ratingallocate->get_choice_groups($choice->id));
                }
            }

        }

        // Setup filter.
        $this->setup_filter($hidenorating, $showallocnecessary, $groupselect);

        $filteredchoices = $this->filter_choiceids(array_keys($this->choicenames));
        foreach ($filteredchoices as $choiceid) {

            $columns[] = self::CHOICE_COL . $choiceid;
            $choice = $this->ratingallocate->get_choices()[$choiceid];
            if ($this->showgroups) {
                $choicegroups = $this->groupnamesofchoices[$choiceid];
                if (!$this->is_downloading() && !empty($choice->usegroups) && !empty($choicegroups)) {
                    $this->choicenames[$choiceid] .= ' <br/>' . \html_writer::span('(' . implode(';', $choicegroups) . ')',
                            'groupsinchoiceheadings');
                }
            }
            $headers[] = $this->choicenames[$choiceid];
            if ($this->is_downloading()) {
                $columns[] = self::CHOICE_COL . $choiceid . self::EXPORT_CHOICE_TEXT_SUFFIX;
                $headers[] = $this->choicenames[$choiceid] . get_string('export_choice_text_suffix', RATINGALLOCATE_MOD_NAME);
                $columns[] = self::CHOICE_COL . $choiceid . self::EXPORT_CHOICE_ALLOC_SUFFIX;
                $headers[] = $this->choicenames[$choiceid] . get_string('export_choice_alloc_suffix', RATINGALLOCATE_MOD_NAME);
            }
        }

        $this->define_columns($columns);
        $this->define_headers($headers);

        // Set additional table settings.
        if ($this->showteams) {
            $this->sortable(true, 'teams');
        } else {
            $this->sortable(true, 'lastname');
        }

        $tableclasses = 'ratingallocate_ratings_table';
        if ($this->showgroups) {
            $tableclasses .= ' includegroups';
            $this->no_sorting('groups');
        }
        if ($this->showteams) {
            $tableclasses .= ' includeteams';
        }

        $this->set_attribute('class', $tableclasses);

        $this->initialbars(true);

        // Perform the rest of the flextable setup.
        parent::setup();

        $this->init_sql();

        $this->add_group_row();
    }

    /**
     * Should be called after setup_choices
     *
     * @param array $ratings an array of ratings -- the data for this table
     * @param array $allocations an array of allocations
     * @param bool $writeable if true the cells are rendered as radio buttons
     */
    public function build_table_by_sql($ratings, $allocations, $writeable = false) {

        global $COURSE;
        $this->writeable = $writeable;

        $users = $this->rawdata;

        if ($this->showteams) {
            // Group all ratings per team to match table structure.
            $ratingsbyteam = [];
            foreach ($ratings as $rating) {
                if (empty($ratingsbyteam[$rating->groupid])) {
                    $ratingsbyteam[$rating->groupid] = [];
                }
                $ratingsbyteam[$rating->groupid][$rating->choiceid] = $rating->rating;
            }
            // Group all memberships per team per choice.
            $allocationsbyteams = [];
            foreach ($allocations as $allocation) {
                foreach ($this->ratingallocate->get_teamids_for_allocation($allocation->id) as $teamid) {
                    if (empty($allocationsbyteams[$teamid])) {
                        $allocationsbyteams[$teamid] = [];
                    }
                    $allocationsbyteams[$teamid][$allocation->choiceid] = true;
                }
            }
            // Add rating rows for each team.
            $teamvotegrouping = $this->ratingallocate->get_teamvote_groupingid();
            $teams = groups_get_all_groups($COURSE->id, 0, $teamvotegrouping);

            foreach ($teams as $team) {
                $teamratings = isset($ratingsbyteam[$team->id]) ? $ratingsbyteam[$team->id] : [];
                $teamallocations = isset($allocationsbyteams[$team->id]) ? $allocationsbyteams[$team->id] : [];
                $this->add_team_ratings_row($team, $teamratings, $teamallocations);
            }

            // We need to add seperate rows for users without team, if preventvotenotingroup is disabled.
            if (!$preventvotenotingroup = $this->ratingallocate->get_preventvotenotingroup()) {
                // There are users that voted, but are not in a team.
                // Get all users not in a group of the teamvote grouping.
                $usersnogroup = [];
                foreach ($this->ratingallocate->get_raters_in_course() as $rater) {
                    if (!in_array($rater, groups_get_grouping_members($teamvotegrouping))) {
                        $usersnogroup[] = $rater;
                    }
                }
                $ratingsbyuser = [];
                foreach ($ratings as $rating) {
                    if (empty($ratingsbyuser[$rating->userid])) {
                        $ratingsbyuser[$rating->userid] = [];
                    }
                    $ratingsbyuser[$rating->userid][$rating->choiceid] = $rating->rating;
                }
                // Group all memberships per user per choice.
                $allocationsbyuser = [];
                foreach ($allocations as $allocation) {
                    if (empty($allocationsbyuser[$allocation->userid])) {
                        $allocationsbyuser[$allocation->userid] = [];
                    }
                    $allocationsbyuser[$allocation->userid][$allocation->choiceid] = true;
                }

                // Add rating rows for each user.
                foreach ($usersnogroup as $user) {
                    $userratings = isset($ratingsbyuser[$user->id]) ? $ratingsbyuser[$user->id] : [];
                    $userallocations = isset($allocationsbyuser[$user->id]) ? $allocationsbyuser[$user->id] : array();
                    $this->add_user_ratings_row_without_team($user, $userratings, $userallocations);
                }

            }
        } else {
            // Group all ratings per user to match table structure.
            $ratingsbyuser = array();
            foreach ($ratings as $rating) {
                if (empty($ratingsbyuser[$rating->userid])) {
                    $ratingsbyuser[$rating->userid] = array();
                }
                $ratingsbyuser[$rating->userid][$rating->choiceid] = $rating->rating;
            }
            // Group all memberships per user per choice.
            $allocationsbyuser = array();
            foreach ($allocations as $allocation) {
                if (empty($allocationsbyuser[$allocation->userid])) {
                    $allocationsbyuser[$allocation->userid] = array();
                }
                $allocationsbyuser[$allocation->userid][$allocation->choiceid] = true;
            }

            // Add rating rows for each user.
            foreach ($users as $user) {
                $userratings = isset($ratingsbyuser[$user->id]) ? $ratingsbyuser[$user->id] : array();
                $userallocations = isset($allocationsbyuser[$user->id]) ? $allocationsbyuser[$user->id] : array();
                $this->add_user_ratings_row($user, $userratings, $userallocations);
            }

        }


        if (!$this->is_downloading()) {
            $this->add_summary_row();
            $this->print_hidden_user_fields($users);
        }

        $this->finish_output();
    }

    /**
     * Add a row containing the group names of the groups assigned to the choices to the export table.
     *
     * @return void
     */
    private function add_group_row(): void {
        if ($this->is_downloading()) {
            $choiceids = array_map(
                function ($c) {
                    return $c->id;
                },
                $this->ratingallocate->get_choices()
            );
            $choices = $this->ratingallocate->get_choices_by_id($this->filter_choiceids($choiceids));
            $row = [];
            foreach ($choices as $choice) {
                $choicegroups = $this->groupnamesofchoices[$choice->id];
                if (empty($choice->usegroups) || empty($choicegroups)) {
                    continue;
                }
                $groupnames = implode(';', $this->groupnamesofchoices[$choice->id]);
                $row[self::CHOICE_COL . $choice->id] = $groupnames;
                $row[self::CHOICE_COL . $choice->id . self::EXPORT_CHOICE_TEXT_SUFFIX] = $groupnames;
                $row[self::CHOICE_COL . $choice->id . self::EXPORT_CHOICE_ALLOC_SUFFIX] = $groupnames;
            }
            $this->add_data_keyed($row);
        }
    }

    /**
     * Adds one row for each user
     *
     * @param $user object of the user for who a row should be added.
     * @param $userratings array consisting of pairs of choiceid to rating for the user.
     * @param $userallocations array constisting of paris of choiceid and allocation of the user.
     */
    private function add_user_ratings_row($user, $userratings, $userallocations) {

        $row = convert_to_array($user);

        if ($this->shownames) {
            $row['fullname'] = $user;
            // We only can add groups if at least one choice has an active group restriction.
            if ($this->showgroups) {
                $groupsofuser = array_filter($this->groupsofallchoices, function($group) use ($user) {
                    return groups_is_member($group->id, $user->id);
                });
                $groupnames = array_map(function($group) {
                    return $group->name;
                }, $groupsofuser);
                $row['groups'] = implode(';', $groupnames);
            }
        }

        foreach ($userratings as $choiceid => $userrating) {
            $row[self::CHOICE_COL . $choiceid] = array(
                    'rating' => $userrating,
                    'hasallocation' => false // May be overridden later.
            );
        }

        // Process allocations separately, since assignment can exist for choices that have not been rated.
        // $userallocations *currently* has 0..1 elements, so this loop is rather fast.
        foreach ($userallocations as $choiceid => $userallocation) {
            if (!$userallocation) {
                // Presumably, $userallocation is always true. But maybe that assumption is wrong someday?
                continue;
            }

            $rowkey = self::CHOICE_COL . $choiceid;
            if (!isset($row[$rowkey])) {
                // User has not rated this choice, but it was assigned to him/her.
                $row[$rowkey] = array(
                        'rating' => null,
                        'hasallocation' => true
                );
            } else {
                // User has rated this choice.
                $row[$rowkey]['hasallocation'] = true;
            }
        }

        $this->add_data_keyed($this->format_row($row));
    }

    /**
     * Adds one row for each team
     *
     * @param $team object of the group for which a row should be added.
     * @param $teamratings array consisting of pairs of choiceid to rating for the team.
     * @param $teamallocations array constisting of pairs of choiceid and allocation of the team.
     */
    private function add_team_ratings_row($team, array $teamratings, array $teamallocations) {

        $row = convert_to_array($team);

        if ($this->shownames) {
            $row['teams'] = groups_get_group_name($team->id);

            // Add names of the teammembers.
            $teammembers = groups_get_members($team->id);
            $namesofteammembers = implode(", ",
                array_map(function($member) {
                    return $member->firstname . " " . $member->lastname;
                }, $teammembers)
            );
            $row['teammembers'] = $namesofteammembers;

            // We only can add groups if at least one choice has an active group restriction.
            if ($this->showgroups) {
                // List groups, that all teammembers are in.
                $groupsofteam = array_filter($this->groupsofallchoices, function($group) use ($teammembers) {
                    foreach ($teammembers as $member) {
                        if (!groups_is_member($group->id, $member->id)) {
                            return false;
                        }
                    }
                    return true;
                });
                $groupnames = array_map(function($group) {
                    return $group->name;
                }, $groupsofteam);
                $row['groups'] = implode(';', $groupnames);
            }
        }

        foreach ($teamratings as $choiceid => $teamrating) {
            $row[self::CHOICE_COL . $choiceid] = array(
                'rating' => $teamrating,
                'hasallocation' => false // May be overridden later.
            );
        }

        // Process allocations separately, since assignment can exist for choices that have not been rated.
        // $teamallocations *currently* has 0..1 elements, so this loop is rather fast.
        foreach ($teamallocations as $choiceid => $teamallocation) {
            if (!$teamallocation) {
                // Presumably, $userallocation is always true. But maybe that assumption is wrong someday?
                continue;
            }

            $rowkey = self::CHOICE_COL . $choiceid;
            if (!isset($row[$rowkey])) {
                // Team has not rated this choice, but it was assigned to it.
                $row[$rowkey] = array(
                    'rating' => null,
                    'hasallocation' => true
                );
            } else {
                // Team has rated this choice.
                $row[$rowkey]['hasallocation'] = true;
            }
        }

        $this->add_data_keyed($this->format_row($row));
    }

    private function add_user_ratings_row_without_team($user, $userratings, $userallocations) {

        $row = convert_to_array($user);

        if ($this->shownames) {
            $row['teams'] = '';
            $row['teammembers'] = $user->firstname . ' ' . $user->lastname;
            // We only can add groups if at least one choice has an active group restriction.
            if ($this->showgroups) {
                $groupsofuser = array_filter($this->groupsofallchoices, function($group) use ($user) {
                    return groups_is_member($group->id, $user->id);
                });
                $groupnames = array_map(function($group) {
                    return $group->name;
                }, $groupsofuser);
                $row['groups'] = implode(';', $groupnames);
            }
        }

        foreach ($userratings as $choiceid => $userrating) {
            $row[self::CHOICE_COL . $choiceid] = array(
                'rating' => $userrating,
                'hasallocation' => false // May be overridden later.
            );
        }

        // Process allocations separately, since assignment can exist for choices that have not been rated.
        // $userallocations *currently* has 0..1 elements, so this loop is rather fast.
        foreach ($userallocations as $choiceid => $userallocation) {
            if (!$userallocation) {
                // Presumably, $userallocation is always true. But maybe that assumption is wrong someday?
                continue;
            }

            $rowkey = self::CHOICE_COL . $choiceid;
            if (!isset($row[$rowkey])) {
                // User has not rated this choice, but it was assigned to him/her.
                $row[$rowkey] = array(
                    'rating' => null,
                    'hasallocation' => true
                );
            } else {
                // User has rated this choice.
                $row[$rowkey]['hasallocation'] = true;
            }
        }

        $this->add_data_keyed($this->format_row($row));
    }

    /**
     * Will be called by build_table when processing the summary row
     */
    private function add_summary_row() {

        $row = array();

        if ($this->shownames) {
            $row[] = get_string('ratings_table_sum_allocations', RATINGALLOCATE_MOD_NAME);
            if ($this->showgroups) {
                // In case we are showing groups, the second column is the group column and needs to be skipped in summary row.
                $row[] = '';
            }
            if ($this->showteams) {
                // In case we are showing teams, the third (second) column is the teams column and needs to be skipped in summary row.
                $row[] = '';
            }
        }

        foreach ($this->choicesum as $choiceid => $sum) {
            if (in_array($choiceid, $this->filter_choiceids(array_keys($this->choicenames)))) {
                $row[] = get_string(
                    'ratings_table_sum_allocations_value',
                    RATINGALLOCATE_MOD_NAME,
                    array('sum' => $sum, 'max' => $this->choicemax[$choiceid])
                );
            }
        }

        $this->add_data($row, 'ratingallocate_summary');
    }

    /**
     * Will be called by $this->format_row when processing the 'choice' columns
     *
     * @param string $column
     * @param object $row
     *
     * @return string rendered choice cell
     */
    public function other_cols($column, $row) {

        // Only supporting 'choice' columns here.
        if (strpos($column, self::CHOICE_COL) !== 0) {
            return null;
        }
        $suffix = '';
        // Suffixes for additional columns have to be removed.
        if ($this->is_downloading()) {
            foreach (array('text', 'alloc') as $key) {
                if (strpos($column, $key)) {
                    $suffix = $key;
                    $column = str_replace($key, '', $column);
                    break;
                }
            }
        }

        if (isset($row->$column)) {
            $celldata = $row->$column;
            if ($celldata['rating'] != null) {
                $ratingtext = $this->titles[$celldata['rating']];
            } else {
                $ratingtext = get_string('no_rating_given', RATINGALLOCATE_MOD_NAME);
            }
            $hasallocation = $celldata['hasallocation'] ? 'checked' : '';
            $ratingclass = $celldata['hasallocation'] ? 'ratingallocate_member' : '';

            if ($this->is_downloading()) {
                if ($suffix === self::EXPORT_CHOICE_TEXT_SUFFIX) {
                    return $ratingtext;
                }
                if ($suffix === self::EXPORT_CHOICE_ALLOC_SUFFIX) {
                    return $celldata['hasallocation'] ? get_string('yes') : get_string('no');
                }
                if ($celldata['rating'] == null) {
                    return "";
                }
                return $celldata['rating'];

            }

            return $this->render_cell($row->id, substr($column, 7),
                    $ratingtext, $hasallocation, $ratingclass);
        } else {

            $ratingtext = get_string('no_rating_given', RATINGALLOCATE_MOD_NAME);

            if ($this->is_downloading()) {
                if ($suffix === self::EXPORT_CHOICE_TEXT_SUFFIX) {
                    return $ratingtext;
                }
                if ($suffix === self::EXPORT_CHOICE_ALLOC_SUFFIX) {
                    return get_string('no');
                }
                return "";
            }

            return $this->render_cell($row->id, substr($column, 7), $ratingtext, '');
        }
    }

    /**
     * Renders a single table cell.
     * The result is either a checkbox, if the table is writeable, or a text otherwise.
     *
     * @param integer $userid
     * @param integer $choiceid
     * @param string $text of the cell
     * @param string $checked string, which represents if the checkbox is checked
     * @param string $class class string, which is added to the input element
     *
     * @return string html of the rendered cell
     */
    private function render_cell($userid, $choiceid, $text, $checked, $class = '') {
        if ($this->writeable) {
            $result = \html_writer::start_span();
            $result .= \html_writer::tag('input', '',
                    array('class' => 'ratingallocate_checkbox_label',
                            'type' => 'radio',
                            'name' => 'allocdata[' . $userid . ']',
                            'id' => 'user_' . $userid . '_alloc_' . $choiceid,
                            'value' => $choiceid,
                            $checked => ''));
            $result .= \html_writer::label(
                    \html_writer::span('', 'ratingallocate_checkbox') . $text,
                    'user_' . $userid . '_alloc_' . $choiceid
            );
            return $result;
        } else {
            return \html_writer::span($text, $class);
        }
    }

    /**
     * Prints one hidden field for every user currently displayed in the table.
     * Is used for checking, which allocation have to be deleted.
     * @param $users array of users displayed for the current filter settings.
     */
    private function print_hidden_user_fields($users) {
        if ($this->writeable) {
            echo \html_writer::start_span();
            foreach ($users as $user) {
                echo \html_writer::tag('input', '',
                        array(
                                'name' => 'userdata[' . $user->id . ']',
                                'value' => $user->id,
                                'type' => 'hidden',
                        ));
            }
            echo \html_writer::end_span();
        }
    }

    /** @var bool Defines if users with no rating at all should be displayed. */
    private $hidenorating = true;
    /** @var bool Defines if only users with no allocation should be displayed. */
    private $showallocnecessary = false;
    /** @var int Defines the group the displayed users are in */
    private $groupselect = 0;

    /**
     * Setup for filtering the table.
     * Loads the filter settings from the user preferences and overrides them if wanted, with the two parameters.
     * @param $hidenorating bool if true it shows also users with no rating.
     * @param $showallocnecessary bool if true it shows only users without allocations.
     */
    private function setup_filter($hidenorating = null, $showallocnecessary = null, $groupselect = null) {
        // Get the filter settings.
        $settings = get_user_preferences('flextable_' . $this->uniqueid . '_filter');
        $filter = $settings ? json_decode($settings, true) : null;

        if (!$filter) {
            $filter = array(
                    'hidenorating' => $this->hidenorating,
                    'showallocnecessary' => $this->showallocnecessary,
                    'groupselect' => $this->groupselect
            );
        }
        if (!is_null($hidenorating)) {
            $filter['hidenorating'] = $hidenorating;
        }
        if (!is_null($showallocnecessary)) {
            $filter['showallocnecessary'] = $showallocnecessary;
        }
        if (!is_null($groupselect)) {
            $filter['groupselect'] = $groupselect;
        }
        set_user_preference('flextable_' . $this->uniqueid . '_filter', json_encode($filter));
        $this->hidenorating = $filter['hidenorating'];
        $this->showallocnecessary = $filter['showallocnecessary'];
        $this->groupselect = $filter['groupselect'];
    }

    /**
     * Gets the filter array used for filtering the table.
     * @return array with keys hidenorating and showallocnecessary
     */
    public function get_filter() {
        $filter = array(
                'hidenorating' => $this->hidenorating,
                'showallocnecessary' => $this->showallocnecessary,
                'groupselect' => $this->groupselect
        );
        return $filter;
    }

    /**
     * Filters a set of given userids in accordance of the two filter variables $hidenorating and $showallocnecessary
     * and the selected group
     * @param $userids array ids, which should be filtered.
     * @return array of filtered user ids.
     */
    private function filter_userids($userids) {
        global $DB;
        if (!$userids) {
            return $userids;
        }
        if (!$this->hidenorating && !$this->showallocnecessary && $this->groupselect == 0) {
            return $userids;
        }
        $sql = "SELECT distinct u.id FROM {user} u ";

        if ($this->hidenorating) {
            $sql .= "JOIN {ratingallocate_ratings} r ON u.id=r.userid " .
                "JOIN {ratingallocate_choices} c ON r.choiceid = c.id " .
                "AND c.ratingallocateid = :ratingallocateid " .
                "AND c.active=1 ";
        }
        if ($this->showallocnecessary) {
            $sql .= "LEFT JOIN ({ratingallocate_allocations} a " .
                "JOIN {ratingallocate_choices} c2 ON c2.id = a.choiceid AND c2.active=1 " .
                "AND a.ratingallocateid = :ratingallocateid2 )" .
                "ON u.id=a.userid " .
                "WHERE a.id is null AND u.id in (" . implode(",", $userids) . ") ";
        } else {
            $sql .= "WHERE u.id in (" . implode(",", $userids) . ") ";
        }
        if ($this->groupselect == -1) {
            $sql .= "AND u.id not in ( SELECT distinct gm.userid FROM {groups_members} gm WHERE gm.groupid in (null";
            if (!empty($gmgroupid = implode(",",
                array_map(
                    function($o) {
                        return $o->id;
                    },
                    $this->groupsofallchoices)))) {
                $sql .= "," . $gmgroupid . ") ) ";
            } else {
                $sql .= "))";
            }
        } else if ($this->groupselect != 0) {
            $sql .= "AND u.id in ( SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid= :groupselect ) ";
        }
        return array_map(
                function($u) {
                    return $u->id;
                },
                $DB->get_records_sql($sql,
                        array(
                                'ratingallocateid' => $this->ratingallocate->ratingallocate->id,
                                'ratingallocateid2' => $this->ratingallocate->ratingallocate->id,
                                'groupselect' => $this->groupselect
                        )
                )
        );
    }

    private function filter_choiceids($choiceids) {
        global $DB;
        if (!$choiceids) {
            return $choiceids;
        }

        if ($this->groupselect == 0) {
            return $choiceids;
        }

        $sql = "SELECT distinct c.id FROM {ratingallocate_choices} c ";

        if ($this->groupselect == -1) {
            $sql .= "WHERE c.usegroups=0 " .
                "AND c.ratingallocateid= :ratingallocateid " .
                "AND c.active=1 " .
                "AND c.id IN (" . implode(",", $choiceids) . ") ";
        } else {
            $sql .= "LEFT JOIN {ratingallocate_group_choices} gc ON c.id=gc.choiceid " .
                "AND c.ratingallocateid= :ratingallocateid " .
                "AND c.active=1 " .
                "WHERE c.id IN (" . implode(",", $choiceids) . ") " .
                "AND ( gc.groupid= :groupselect OR c.usegroups=0) ";
        }

        return array_map(
            function($c) {
                return $c->id;
            },
            $DB->get_records_sql($sql,
                array(
                    'ratingallocateid' => $this->ratingallocate->ratingallocate->id,
                    'groupselect' => $this->groupselect
                )
            )
        );

    }

    private function sort_by_teams ($teams) {

    }

    /**
     * Sets up the sql statement for querying the table data.
     */
    public function init_sql() {
        $userids = array_map(function($c) {
            return $c->id;
        },
                $this->ratingallocate->get_raters_in_course());
        $userids = $this->filter_userids($userids);

        $sortfields = $this->get_sort_columns();


        // To do vardumps entfernen.
        var_dump($sortfields);
        var_dump("</br> sortdata: ");
        var_dump($this->sortdata);
        var_dump("</br> sortorder: ");
        var_dump($this->get_sort_order());

        // If we have teamvote enabled, always order by team first, in order to always show users in their teams.
        if ($this->showteams) {

            $sortdata = array([
                'sortby' => 'teams',
                'sortorder' => SORT_ASC
            ]);

            foreach (array_keys($sortfields) as $column) {
                if (substr($column, 0, 5) != "teams") {
                    $sortdata[] =[
                        'sortby' => $column,
                        'sortorder' => SORT_ASC
                    ];
                }
            }
            $this->set_sortdata($sortdata);
            $this->set_sorting_preferences();

        }
        $sortfields = $this->get_sort_columns();

        var_dump("</br> sortdata nach preferences: ");
        var_dump($this->sortdata);
        var_dump("</br> sortcolumns nach preferences: ");
        var_dump($this->get_sort_columns());
        var_dump("</br> sortorder nach preferences: ");
        var_dump($this->get_sort_order());


        $fields = "u.*";
        if ($userids) {
            $where = "u.id in (" . implode(",", $userids) . ")";
        } else {
            $where = "u.id is null";
        }

        $from = "{user} u";

        $params = array();
        for ($i = 0; $i < count($sortfields); $i++) {
            $key = array_keys($sortfields)[$i];

            // If sortfields contain 'teams', it is always on first position.
            if (substr($key, 0, 6) == "choice") {
                $id = substr($key, 7);
                $from .= " LEFT JOIN {ratingallocate_ratings} r$i ON u.id = r$i.userid AND r$i.choiceid = :choiceid$i ";
                $fields .= ", r$i.rating as $key";
                $params["choiceid$i"] = $id;
            } else if (substr($key, 0, 5) == "teams") {
                $fields .= ", gm.groupid as teams";
                $from .= " LEFT JOIN {groups_members} gm ON u.id=gm.userid LEFT JOIN {groupings_groups} gg ON gm.groupid=gg.groupid
                  LEFT JOIN {ratingallocate} r ON gg.groupingid=r.teamvotegroupingid";
                $where .= " AND r.id = :ratingallocateid";
                $params["ratingallocateid"] = $this->ratingallocate->get_ratingallocateid();
            }
        }

        $this->set_sql($fields, $from, $where, $params);

        $this->query_db(20);
    }

}
