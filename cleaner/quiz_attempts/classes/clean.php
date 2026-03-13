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

namespace cleaner_quiz_attempts;

/**
 * Data cleaner for quiz attempts.
 *
 * Deletes quiz attempts and cascades through the question engine tables:
 * quiz_attempts -> question_usages -> question_attempts -> question_attempt_steps
 * -> question_attempt_step_data, plus quiz_grades, quiz_overview_regrades, and
 * response files.
 *
 * @package    cleaner_quiz_attempts
 * @copyright  2026 Catalyst IT Canada
 * @author     Artem Garanin <artemgaranin@catalyst-ca.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clean extends \local_datacleaner\clean {
    /** @var string Task name for progress display. */
    const TASK = 'Deleting quiz attempts';

    /** @var int Number of quiz attempts to process per chunk. */
    const CHUNK_SIZE = 1000;

    /**
     * Execute the cleaning process.
     */
    public static function execute() {
        global $DB;

        $config = get_config('cleaner_quiz_attempts');
        $minimumage = isset($config->minimumage) ? (int) $config->minimumage : 365;

        if ($minimumage === 0) {
            if (self::$options['dryrun']) {
                echo "Would delete ALL quiz attempt data (minimumage = 0).\n";
                return;
            }
            self::delete_all();
            return;
        }

        $cutoff = time() - ($minimumage * DAYSECS);

        $count = $DB->count_records_select('quiz_attempts', 'timestart < :cutoff', ['cutoff' => $cutoff]);

        if ($count == 0) {
            echo "No quiz attempts to delete.\n";
            return;
        }

        if (self::$options['dryrun']) {
            echo "Would delete {$count} quiz attempts older than {$minimumage} days.\n";
            return;
        }

        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $chunks = (int) ceil($count / self::CHUNK_SIZE);
        self::new_task($chunks);

        $lastid = 0;
        $deleted = 0;

        while (true) {
            $attempts = $DB->get_records_select(
                'quiz_attempts',
                'timestart < :cutoff AND id > :lastid',
                ['cutoff' => $cutoff, 'lastid' => $lastid],
                'id ASC',
                'id, uniqueid, quiz, userid',
                0,
                self::CHUNK_SIZE
            );

            if (empty($attempts)) {
                break;
            }

            $ids = [];
            foreach ($attempts as $attempt) {
                // Delete question usage cascade (question_attempts, steps, step_data, files).
                try {
                    \question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
                } catch (\Exception $e) {
                    self::debug("Failed to delete question usage {$attempt->uniqueid}: " . $e->getMessage());
                }

                // Delete overview regrades for this usage.
                $DB->delete_records('quiz_overview_regrades', ['questionusageid' => $attempt->uniqueid]);

                $ids[] = $attempt->id;
                $lastid = $attempt->id;
            }

            // Bulk delete quiz_attempts for this chunk.
            [$sql, $params] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('quiz_attempts', "id {$sql}", $params);

            $deleted += count($ids);
            self::next_step();
            self::debugmemory();
        }

        // Clean up quiz_grades for users with no remaining attempts.
        $DB->execute(
            "DELETE FROM {quiz_grades}
              WHERE NOT EXISTS (
                  SELECT 1 FROM {quiz_attempts} qa
                   WHERE qa.quiz = {quiz_grades}.quiz AND qa.userid = {quiz_grades}.userid
              )"
        );

        self::println("Deleted {$deleted} quiz attempts.");
    }

    /**
     * Fast path: delete all quiz attempt data when minimumage = 0.
     *
     * Truncates child tables first, then parent tables, in dependency order.
     */
    protected static function delete_all() {
        global $DB;

        self::println("Deleting ALL quiz attempt data (minimumage = 0).");

        $DB->delete_records('question_attempt_step_data');
        self::println("Deleted question_attempt_step_data.");

        $DB->delete_records('question_attempt_steps');
        self::println("Deleted question_attempt_steps.");

        $DB->delete_records('question_attempts');
        self::println("Deleted question_attempts.");

        $DB->delete_records('question_usages');
        self::println("Deleted question_usages.");

        $DB->delete_records('quiz_attempts');
        self::println("Deleted quiz_attempts.");

        $DB->delete_records('quiz_grades');
        self::println("Deleted quiz_grades.");

        $DB->delete_records('quiz_overview_regrades');
        self::println("Deleted quiz_overview_regrades.");

        $DB->delete_records_select('files', "component = 'question' AND filearea LIKE 'response_%'");
        self::println("Deleted orphaned response files.");

        self::println("All quiz attempt data deleted.");
    }
}
