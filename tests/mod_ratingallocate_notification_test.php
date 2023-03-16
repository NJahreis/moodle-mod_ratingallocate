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

/**
 * Tests the notifications when allocations are published.
 *
 * @package    mod_ratingallocate
 * @category   test
 * @group      mod_ratingallocate
 * @copyright  2018 T Reischmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_ratingallocate_notification_test extends \advanced_testcase {

    /**
     * Tests of publishing the allocation sends messages to all users with ratings
     */
    public function test_allocation_notification_send() {
        $course = $this->getDataGenerator()->create_course();
        $ratingallocate = mod_ratingallocate_generator::get_closed_ratingallocate_for_teacher($this, null, $course);

        // Count the number of allocations.
        $allocations = $ratingallocate->get_allocations();
        $allocationcount = count($allocations);

        // Create additional user to test if the user does not recieve a mail.
        $teststudent = mod_ratingallocate_generator::create_user_and_enrol($this, $course);

        $this->preventResetByRollback();
        $messagessink = $this->redirectMessages();
        $emailsink = $this->redirectEmails();

        // Create notification task.
        $task = new \mod_ratingallocate\task\send_distribution_notification();

        // Add custom data.
        $task->set_component('mod_ratingallocate');
        $task->set_custom_data(array (
            'ratingallocateid' => $ratingallocate->ratingallocate->id
        ));

        $this->setAdminUser();
        $task->execute();

        // Every rating should result in one sent mail and message.
        $messages = $messagessink->get_messages();
        $this->assertEquals($allocationcount, count($messages));
        $emails = $emailsink->get_messages();
        $this->assertEquals($allocationcount, count($emails));

        // Check if every student with an allocation recieved a message and mail.
        foreach ($allocations as $allocation) {
            $this->assertArrayHasKey($messages, $allocation->userid);
            $this->assertArrayHasKey($emails, $allocation->userid);
        }

        // Check if no mail was sent to the test user without a rating.
        $this->assert_no_message_for_user($messages, $teststudent->id);
        $this->assert_no_message_for_user($emails, $teststudent->id);
    }

    /**
     * Tests if publishing the allocation send messages with the right content to the right users.
     *
     * @covers ::send_distribution_notification()
     */
    public function test_allocation_notification() {
        $course = $this->getDataGenerator()->create_course();
        $students = array();
        for ($i = 1; $i <= 4; $i++) {
            $students[$i] = \mod_ratingallocate_generator::create_user_and_enrol($this, $course);
        }
        [$choices, $ratings] = $this->generate_choices_and_ratings($students);

        $ratingallocate = \mod_ratingallocate_generator::get_closed_ratingallocate_for_teacher($this, $choices,
                $course, $ratings);
        $allocations = $ratingallocate->get_allocations();
        $this->assertArrayHasKey($students[1]->id, $allocations);
        $this->assertArrayHasKey($students[2]->id, $allocations);
        $this->assertCount(2, $allocations);
        $ratingchoices = $ratingallocate->get_choices();
        $this->assertEquals($choices[0]['title'], $ratingchoices[$allocations[$students[1]->id]->choiceid]->title);
        $this->assertEquals($choices[1]['title'], $ratingchoices[$allocations[$students[2]->id]->choiceid]->title);

        $this->preventResetByRollback();
        $messagesink = $this->redirectMessages();

        // Create a notification task.
        $task = new \mod_ratingallocate\task\send_distribution_notification();

        // Add custom data.
        $task->set_component('mod_ratingallocate');
        $task->set_custom_data(array(
                'ratingallocateid' => $ratingallocate->ratingallocate->id
        ));

        $this->setAdminUser();
        $task->execute();

        $messages = $messagesink->get_messages();
        $this->assertEquals(3, count($messages));
        $this->assert_message_contains($messages, $students[1]->id, $choices[0]['title']);
        $this->assert_message_contains($messages, $students[2]->id, $choices[1]['title']);
        $this->assert_message_contains($messages, $students[3]->id, 'could not');
        $this->assert_no_message_for_user($messages, $students[4]->id);
    }

    /**
     * Tests if publishing the allocation sends messages with the right content to the right users with custom messages.
    */
     public function test_allocation_customnotification() {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $courselink = new moodle_url('/course/view.php', array('id' => $course->id));
        $students = array();
        for ($i = 1; $i <= 4; $i++) {
            $students[$i] = mod_ratingallocate_generator::create_user_and_enrol($this, $course);
        }

        [$choices, $ratings] = $this->generate_choices_and_ratings($students);

        $ratingallocate = mod_ratingallocate_generator::get_closed_ratingallocate_for_teacher($this, $choices,
            $course, $ratings);

        $record = $DB->get_records('ratingallocate', array('course' => $course->id))[0];
        $coursemoduleid = get_all_instances_in_course('ratingallocate', $course)[0]->coursemodule;
        $ratingallocatelink = new moodle_url('/mod/ratingallocate/view.php', array('id' => $coursemoduleid));

        $allocations = $ratingallocate->get_allocations();
        $this->assertArrayHasKey($students[1]->id, $allocations);
        $this->assertArrayHasKey($students[2]->id, $allocations);
        $this->assertCount(2, $allocations);
        $ratingchoices = $ratingallocate->get_choices();
        $this->assertEquals($choices[0]['title'], $ratingchoices[$allocations[$students[1]->id]->choiceid]->title);
        $this->assertEquals($choices[1]['title'], $ratingchoices[$allocations[$students[2]->id]->choiceid]->title);

        // Set custom message settings for the ratingallocate instance.
        $DB->set_field('ratingallocate', 'enablecustommessage', 1, array('id' => $record['id']));
        $testsubject = 'This ##firstname## is ##lastname## a ##choice## test ##choiceexplanation## string ##activityname## ' .
            'for ##link## the ##coursename## variable ##courselink## substitution';
        $testcontent = 'This ##firstname## is ##lastname## a ##choice## test ##choiceexplanation## string ##activityname## ' .
            'for ##link## the ##coursename## variable ##courselink## substitution';
        $testcontenthtml = 'This ##firstname## is ##lastname## a ##choice## test ##choiceexplanation## string ##activityname## ' .
            'for ##link-html## the ##coursename## variable ##courselink-html## substitution';
        $DB->set_field('ratingallocate', 'emailsubject', $testsubject, array('id' => $record['id']));
        $DB->set_field('ratingallocate', 'emailcontent', $testcontent, array('id' => $record['id']));
        $DB->set_field('ratingallocate', 'emailcontenthtml', $testcontenthtml, array('id' => $record['id']));

        $messages = $this->redirectMessages();
        $emails = $this->redirectEmails();

        // Create a notification task.
        $task = new mod_ratingallocate\task\send_distribution_notification();

        // Add custom data.
        $task->set_component('mod_ratingallocate');
        $task->set_custom_data(array('ratingallocateid' => $ratingallocate->ratingallocate->id));

        $this->preventResetByRollback();
        unset_config('noemailever');

        $this->setAdminUser();
        $task->execute();

        // Check if the correct number of messages and mails was send.
        $this->assertEquals(3, count($this->$messages));
        $this->assertEquals(3, count($this->$emails));
        // Check if student[4] has no mail.
        $this->assert_no_message_for_user($messages, $students[4]->id);

        // Test if all custom fiels are correctly found in the moodle message:
        // Also tests if the correct key is replaced by the correct string.
        // First test the internal generated messages:
        foreach (array_slice($students, 1, 3) as $student) {
            // 1) ##firstname##.
            $this->assert_message_contains($this->$messages, $student->id, 'This ' . $student->firstname . ' is');
            // 2) ##lastname##.
            $this->assert_message_contains($this->$messages, $student->id, 'is '. $student->lastname . ' a');
            // 3) ##choice## OR 'You could not be assigned to any choice.' in case of student 3.
            // 4) ##choiceexplanation## Empty for student 3.
            if ($student->id < 3) {
                $this->assert_message_contains($this->$messages, $student->id, 'a ' . $choices[$student->id]->title) . ' test';
                $this->assert_message_contains($this->$messages, $student->id, 'test ' . $choices[$student->id]->explanation) . ' string';
            }
            else {
                $this->assert_message_contains($this->$messages, $student->id, 'a You could not be assigned to any choice. test');
                $this->assert_message_contains($this->$messages, $student->id, 'test ' . '' . ' string');
            }
            // 5) ##activityname##.
            $this->assert_message_contains($this->$messages, $student->id, 'string ' . $record->name . ' for');
            // 6) ##link##.
            $this->assert_message_contains($this->$messages, $student->id, 'for ' . $ratingallocatelink->out() . ' the');
            // 7) ##coursename##.
            $this->assert_message_contains($this->$messages, $student->id, 'the ' . $course->shortname . ' variable');
            // 8) ##courselink##.
            $this->assert_message_contains($this->$messages, $student->id, 'variable ' . $courselink->out() . ' substitution');
        }
    }

    /**
     * Asserts that a message for a user exists and that it contains a certain search string
     * @param $messages \stdClass[] received messages
     * @param $userid int id of the user
     * @param $needle string search string
     */
    private function assert_message_contains($messages, $userid, $needle) {
        $messageexists = false;
        foreach ($messages as $message) {
            if ($message->useridto == $userid) {
                $messageexists = true;
                $this->assertStringContainsString($needle, $message->fullmessage);
            }
        }
        $this->assertTrue($messageexists, 'Message for userid ' . $userid . 'could not be found.');
    }

    /**
     * Asserts that there is no message for a certain user.
     * @param $messages \stdClass[] received messages
     * @param $userid int id of the user
     */
    private function assert_no_message_for_user($messages, $userid) {
        $messageexists = false;
        foreach ($messages as $message) {
            if ($message->useridto == $userid) {
                $messageexists = true;
            }
        }
        $this->assertFalse($messageexists, 'There is a message for userid ' . $userid . '.');
    }

    /**
     * Generate choices and ratings for students for notification tests
     * @param $students array Array of students
     * @return array Array containing the choices and the students ratings
     */
    private function generate_choices_and_ratings($students) {
        $choices = array(
            array(
                'title' => 'Choice 1',
                'explanation' => 'This is Choice 1',
                'maxsize' => '1',
                'active' => '1',
            ),
            array(
                'title' => 'Choice 2',
                'explanation' => 'This is Choice 2',
                'maxsize' => '1',
                'active' => '1',
            )
        );
        $ratings = array(
            $students[1]->id => array(
                array(
                    'choice' => $choices[0]['title'],
                    'rating' => 1
                ),
                array(
                    'choice' => $choices[1]['title'],
                    'rating' => 0
                )
            ),
            $students[2]->id => array(
                array(
                    'choice' => $choices[0]['title'],
                    'rating' => 0
                ),
                array(
                    'choice' => $choices[1]['title'],
                    'rating' => 1
                )
            ),
            $students[3]->id => array(
                array(
                    'choice' => $choices[0]['title'],
                    'rating' => 0
                ),
                array(
                    'choice' => $choices[1]['title'],
                    'rating' => 0
                )
            )
        );

        return array (
            $choices,
            $ratings
        );
    }
