<?php
/* For licensing terms, see /license.txt */

/**
 * Chamilo LMS
 *
 * @package chamilo.install
 */

$old_file_version = '1.8.8';
$new_file_version = '1.9.0';

if (defined('SYSTEM_INSTALLATION')) {

    $only_test = false;

    $singleDbForm = false;

    $dbNameForm = $_configuration['main_database'];
    $dbStatsForm = isset($_configuration['statistics_database']) ? $_configuration['statistics_database'] : $_configuration['main_database'];
    $dbUserForm  = isset($_configuration['user_personal_database']) ? $_configuration['user_personal_database'] : $_configuration['main_database'];

    //Migrate classes to the new classes (usergroups)

    $sql = "SELECT selected_value FROM $dbNameForm.settings_current WHERE variable='use_session_mode' ";
    $result = iDatabase::query($sql);
    $result = Database::fetch_array($result);
    $session_mode  = $result['selected_value'];

    if ($session_mode == 'true') {

        $sql = "UPDATE $dbNameForm.settings_current SET selected_value = 'true' WHERE variable='use_session_mode' ";
        $result = iDatabase::query($sql);

        $sql = "SELECT * FROM $dbNameForm.class";
        $result = iDatabase::query($sql);
        $count = 0;
        $new_table = "$dbNameForm.usergroup";
        $classes_added = 0;
        $mapping_classes = array();
        if (Database::num_rows($result)) {
            while($row = iDatabase::fetch_array($result, 'ASSOC')) {
                $old_id = $row['id'];
                unset($row['id']);
                unset($row['code']);
                $new_user_group_id = Database::insert($new_table, $row);
                $mapping_classes[$old_id] = $new_user_group_id;
                if (is_numeric($id)) {
                    $classes_added ++;
                }
            }
        }

        $sql = "SELECT * FROM $dbNameForm.class_user";
        $result = iDatabase::query($sql);
        $new_table = "$dbNameForm.usergroup_rel_user";
        if (Database::num_rows($result)) {
            while ($row = iDatabase::fetch_array($result, 'ASSOC')) {
                $values = array('usergroup_id' => $mapping_classes[$row['class_id']],
                                'user_id' => $row['user_id']);
                iDatabase::insert($new_table, $values);
            }
        }

        $sql = "SELECT * FROM $dbNameForm.course_rel_class";
        $result = iDatabase::query($sql);
        $new_table = "$dbNameForm.usergroup_rel_course";

        if (Database::num_rows($result)) {
            while ($row = iDatabase::fetch_array($result, 'ASSOC')) {
                $course_code = $row['course_code'];
                $course_code = addslashes($course_code);
                $sql_course = "SELECT id from $dbNameForm.course WHERE code = '$course_code'";
                $result_course = iDatabase::query($sql_course);
                $result_course = Database::fetch_array($result_course);
                $course_id  = $result_course['id'];
                $values = array('usergroup_id' => $mapping_classes[$row['class_id']],
                                'course_id' => $course_id);
                iDatabase::insert($new_table, $values);
            }
        }

        $app['monolog']->addInfo("#classes added $classes_added");
    }

    //Moving Stats DB to the main DB

    $stats_table = array(
        "track_c_browsers",
        "track_c_countries",
        "track_c_os",
        "track_c_providers",
        "track_c_referers",
        "track_e_access",
        "track_e_attempt",
        "track_e_attempt_recording",
        "track_e_course_access",
        "track_e_default",
        "track_e_downloads",
        "track_e_exercices",
        "track_e_hotpotatoes",
        "track_e_hotspot",
        "track_e_item_property",
        "track_e_lastaccess",
        "track_e_links",
        "track_e_login",
        "track_e_online",
        "track_e_open",
        "track_e_uploads",
        "track_stored_values",
        "track_stored_values_stack",
    );

    if ($dbNameForm != $dbStatsForm) {
        iDatabase::select_db($dbStatsForm);
        foreach ($stats_table as $stat_table) {
            $sql = "ALTER TABLE $dbStatsForm.$stat_table RENAME $dbNameForm.$stat_table";
            iDatabase::query($sql);
        }
        iDatabase::select_db($dbNameForm);
    }

    //Moving user database to the main database
    $users_tables = array(
        "personal_agenda",
        "personal_agenda_repeat",
        "personal_agenda_repeat_not",
        "user_course_category"
    );

    if ($dbNameForm != $dbUserForm) {
        iDatabase::select_db($dbUserForm);
        foreach ($users_tables as $table) {
            $sql = "ALTER TABLE $dbUserForm.$table RENAME $dbNameForm.$table";
            iDatabase::query($sql);
        }
        iDatabase::select_db($dbNameForm);
    }

    //Adding admin user in the access_url_rel_user  table
    $sql = "SELECT user_id FROM admin WHERE user_id = 1";
    $result = iDatabase::query($sql);
    $has_user_id = Database::num_rows($result) > 0;

    $sql = "SELECT * FROM access_url_rel_user WHERE user_id = 1 AND access_url_id = 1";
    $result = iDatabase::query($sql);
    $has_entry = Database::num_rows($result) > 0;

    if ($has_user_id && !$has_entry) {
        $sql = "INSERT INTO access_url_rel_user VALUES(1, 1)";
        iDatabase::query($sql);
    }


    $this->dropCourseTables();
    $this->createCourseTables($output);

    $prefix = '';
    if ($singleDbForm) {
        $prefix =  $_configuration['table_prefix'];
    }

    $app['monolog']->addInfo("Database prefix: '$prefix'");

    // Get the courses databases queries list (c_q_list)

    // Get the courses list
    if (strlen($dbNameForm) > 40) {
        $app['monolog']->addError('Database name '.$dbNameForm.' is too long, skipping');
    } elseif(!in_array($dbNameForm, $dblist)) {
        $app['monolog']->addError('Database '.$dbNameForm.' was not found, skipping');
    } else {
        iDatabase::select_db($dbNameForm);
        $res = iDatabase::query("SELECT id, code, db_name, directory, course_language, id as real_id FROM course WHERE target_course_code IS NULL ORDER BY code");

        if ($res === false) { die('Error while querying the courses list in update_db-1.8.8-1.9.0.inc.php'); }

        $errors = array();

        if (iDatabase::num_rows($res) > 0) {
            $i = 0;
            $list = array();
            while ($row = iDatabase::fetch_array($res)) {
                $list[] = $row;
                $i++;
            }

            foreach ($list as $row_course) {
                if (!$singleDbForm) { // otherwise just use the main one
                    iDatabase::select_db($row_course['db_name']);
                }
                $app['monolog']->addInfo('Course db ' . $row_course['db_name']);

                $work_table = $row_course['db_name'].".student_publication";
                $item_table = $row_course['db_name'].".item_property";

                if ($singleDbForm) {
                    $work_table = "$prefix{$row_course['db_name']}_student_publication";
                    $item_table = $row_course['db_name'].".item_property";
                }

                if (!$singleDbForm) {
                    // otherwise just use the main one
                    iDatabase::select_db($row_course['db_name']);
                } else {
                    iDatabase::select_db($dbNameForm);
                }


                /* Start work fix */

                /* Fixes the work subfolder and work with no parent issues */

                //1. Searching for works with no parents
                $sql 	= "SELECT * FROM $work_table WHERE parent_id = 0 AND filetype ='file'";
                $result = Database::query($sql);

                $work_list = array();

                if (Database::num_rows($result)) {
                    while ($row = Database::fetch_array($result, 'ASSOC')) {
                        $work_list[] = $row;
                    }
                }

                $today = api_get_utc_datetime();
                $user_id = 1;
                require_once api_get_path(SYS_CODE_PATH).'work/work.lib.php';

                $sys_course_path 	= api_get_path(SYS_COURSE_PATH);
                $course_dir 		= $sys_course_path . $row_course['directory'];
                $base_work_dir 		= $course_dir . '/work';

                //2. Looping if there are works with no parents
                if (!empty($work_list)) {
                    $work_dir_created = array();

                    foreach ($work_list as $work) {
                        $session_id = intval($work['session_id']);
                        $group_id   = intval($work['post_group_id']);
                        $work_key   = $session_id.$group_id;

                        //Only create the folder once
                        if (!isset($work_dir_created[$work_key])) {

                            $dir_name = "default_tasks_".$group_id."_".$session_id;

                            //2.1 Creating a new work folder
                            $sql = "INSERT INTO $work_table SET
                                            url         		= 'work/".$dir_name."',
                                            title               = 'Tasks',
                                            description 		= '',
                                            author      		= '',
                                            active              = '1',
                                            accepted			= '1',
                                            filetype            = 'folder',
                                            post_group_id       = '$group_id',
                                            sent_date           = '".$today."',
                                            parent_id           = '0',
                                            qualificator_id     = '',
                                            user_id 			= '".$user_id."'";
                            iDatabase::query($sql);

                            $id = Database::insert_id();
                            //2.2 Adding the folder in item property
                            if ($id) {
                                //api_item_property_update($row_course, 'work', $id, 'DirectoryCreated', $user_id, $group_id, null, 0, 0 , $session_id);
                                $sql = "INSERT INTO $item_table (tool, ref, insert_date, insert_user_id, lastedit_date, lastedit_type, lastedit_user_id, to_group_id, visibility, id_session)
                                        VALUES ('work','$id','$today', '$user_id', '$today', 'DirectoryCreated','$user_id', '$group_id', '1', '$session_id')";

                                iDatabase::query($sql);
                                $work_dir_created[$work_key] = $id;
                                create_unexisting_work_directory($base_work_dir, $dir_name);
                                $final_dir = $base_work_dir.'/'.$dir_name;
                            }
                        } else {
                            $final_dir = $base_work_dir.'/'.$dir_name;
                        }

                        //2.3 Updating the url
                        if (!empty($work_dir_created[$work_key])) {
                            $parent_id = $work_dir_created[$work_key];
                            $new_url = "work/".$dir_name.'/'.basename($work['url']);
                            $new_url = Database::escape_string($new_url);$sql = "UPDATE $work_table SET url = '$new_url', parent_id = $parent_id, contains_file = '1' WHERE id = {$work['id']}";
                            iDatabase::query($sql);
                            if (is_dir($final_dir)) {
                                rename($course_dir.'/'.$work['url'], $course_dir.'/'.$new_url);
                            }
                        }
                    }
                }

                //3.0 Moving subfolders to the root
                $sql 	= "SELECT * FROM $work_table WHERE parent_id <> 0 AND filetype ='folder'";
                $result = Database::query($sql);
                $work_list = array();

                if (Database::num_rows($result)) {
                    while ($row = Database::fetch_array($result, 'ASSOC')) {
                        $work_list[] = $row;
                    }
                    if (!empty($work_list)) {
                        foreach ($work_list as $work_folder) {
                            $folder_id = $work_folder['id'];
                            check_work($folder_id, $work_folder['url'], $work_table, $base_work_dir);
                        }
                    }
                }

                /*  End of work fix  */

                //Course tables to be migrated
                $table_list = array(
                    'announcement',
                    'announcement_attachment',
                    'attendance',
                    'attendance_calendar',
                    'attendance_result',
                    'attendance_sheet',
                    'attendance_sheet_log',
                    'blog',
                    'blog_attachment',
                    'blog_comment',
                    'blog_post',
                    'blog_rating',
                    'blog_rel_user',
                    'blog_task',
                    'blog_task_rel_user',
                    'calendar_event',
                    'calendar_event_attachment',
                    'calendar_event_repeat',
                    'calendar_event_repeat_not',
                    'chat_connected',
                    'course_description',
                    'course_setting',
                    'document',
                    'dropbox_category',
                    'dropbox_feedback',
                    'dropbox_file',
                    'dropbox_person',
                    'dropbox_post',
                    'forum_attachment',
                    'forum_category',
                    'forum_forum',
                    'forum_mailcue',
                    'forum_notification',
                    'forum_post',
                    'forum_thread',
                    'forum_thread_qualify',
                    'forum_thread_qualify_log',
                    'glossary',
                    'group_category',
                    'group_info',
                    'group_rel_tutor',
                    'group_rel_user',
                    'item_property',
                    'link',
                    'link_category',
                    'lp',
                    'lp_item',
                    'lp_item_view',
                    'lp_iv_interaction',
                    'lp_iv_objective',
                    'lp_view',
                    'notebook',
                    'metadata',
                    'online_connected',
                    'online_link',
                    'permission_group',
                    'permission_task',
                    'permission_user',
                    'quiz',
                    'quiz_answer',
                    'quiz_question',
                    'quiz_question_option',
                    'quiz_rel_question',
                    'resource',
                    'role',
                    'role_group',
                    'role_permissions',
                    'role_user',
                    'student_publication',
                    'student_publication_assignment',
                    'survey',
                    'survey_answer',
                    'survey_group',
                    'survey_invitation',
                    'survey_question',
                    'survey_question_option',
                    'thematic',
                    'thematic_advance',
                    'thematic_plan',
                    'tool',
                    'tool_intro',
                    'userinfo_content',
                    'userinfo_def',
                    'wiki',
                    'wiki_conf',
                    'wiki_discuss',
                    'wiki_mailcue'
                );

                $app['monolog']->addInfo('<<<------- Loading DB course '.$row_course['db_name'].' -------->>');

                $count = $old_count = 0;
                foreach ($table_list as $table) {
                    $just_table_name = $table;
                    $old_table = $row_course['db_name'].".".$table;

                    if ($singleDbForm) {
                        $old_table = "$prefix{$row_course['db_name']}_".$table;
                        $just_table_name = "$prefix{$row_course['db_name']}_".$table;
                    }

                    $course_id = $row_course['id'];
                    $new_table = DB_COURSE_PREFIX.$table;

                    //Use the old database (if this is the case)

                    if (!$singleDbForm) {
                        // otherwise just use the main one
                        iDatabase::select_db($row_course['db_name']);
                    } else {
                        iDatabase::select_db($dbNameForm);
                    }

                    //Count of rows
                    $sql 	= "SHOW TABLES LIKE '$just_table_name'";
                    $result = iDatabase::query($sql);

                    if (Database::num_rows($result)) {

                        $sql 	= "SELECT count(*) FROM $old_table";
                        $result = iDatabase::query($sql);

                        $old_count = 0;
                        if ($result) {
                            $row 		= iDatabase::fetch_row($result);
                            $old_count = $row[0];
                        } else {
                            $app['monolog']->addError("Count(*) in table $old_table failed");
                        }

                        $app['monolog']->addInfo("# rows in $old_table: $old_count");

                        $sql = "SELECT * FROM $old_table";
                        $result = iDatabase::query($sql);

                        $count = 0;

                        /* Loads the main database */
                        iDatabase::select_db($dbNameForm);

                        while($row = iDatabase::fetch_array($result, 'ASSOC')) {
                            $row['c_id'] = $course_id;
                            $id = iDatabase::insert($new_table, $row);
                            if (is_numeric($id)) {
                                $count++;
                            } else {
                                $errors[$old_table][] = $row;
                            }
                        }
                        $app['monolog']->addInfo("#rows inserted in $new_table: $count");

                        if ($old_count != $count) {
                            $app['monolog']->addError("ERROR count of new and old table doesn't match: $old_count - $new_table");
                            $app['monolog']->addError("Check the results: ");
                            $app['monolog']->addError(print_r($errors, 1));
                            error_log(print_r($errors, 1));
                        }
                    } else {
                        $app['monolog']->addError("Seems that the table $old_table doesn't exists ");
                    }
                }
                $app['monolog']->addInfo('<<<------- end  -------->>');
            }
        }
    }
} else {
    echo 'You are not allowed here !' . __FILE__;
}

function check_work($folder_id, $work_url, $work_table, $base_work_dir) {
    $uniq_id = uniqid();
    //Looking for subfolders
    $sql 	= "SELECT * FROM $work_table WHERE parent_id = $folder_id AND filetype ='folder'";
    $result = Database::query($sql);

    if (Database::num_rows($result)) {
        while ($row = Database::fetch_array($result, 'ASSOC')) {
            check_work($row['id'], $row['url'], $work_table, $base_work_dir);
        }
    }

    //Moving the subfolder in the root
    $new_url = '/'.basename($work_url).'_mv_'.$uniq_id;
    $new_url = Database::escape_string($new_url);
    $sql = "UPDATE $work_table SET url = '$new_url', parent_id = 0 WHERE id = $folder_id";
    iDatabase::query($sql);

    if (is_dir($base_work_dir.$work_url)) {
        rename($base_work_dir.$work_url, $base_work_dir.$new_url);

        //Rename all files inside the folder
        $sql 	= "SELECT * FROM $work_table WHERE parent_id = $folder_id AND filetype ='file'";
        $result = Database::query($sql);

        if (Database::num_rows($result)) {
            while ($row = Database::fetch_array($result, 'ASSOC')) {
                $new_url = "work".$new_url.'/'.basename($row['url']);
                $new_url = Database::escape_string($new_url);
                $sql = "UPDATE $work_table SET url = '$new_url', parent_id = $folder_id, contains_file = '1' WHERE id = {$row['id']}";
                iDatabase::query($sql);
            }
        }
    }
}