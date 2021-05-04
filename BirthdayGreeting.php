<?php
namespace Stanford\BirthdayGreeting;

require_once "emLoggerTrait.php";
require_once "EMConfigurationException.php";

use ExternalModules\ExternalModules;
use \REDCap;
use Project;
use Alerts;
use DateTime;
use Stanford\BirthdayGreeting\EMConfigurationException as EMConfigurationException;


class BirthdayGreeting extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;


    /*******************************************************************************************************************/
    /* CRON METHODS                                                                                                    */
    /***************************************************************************************************************** */

    public function cronGreeting() {
        $this->emDebug("AAH");
        $this->emDebug("Starting send cron for birthday greetings " . $this->PREFIX);

        //1. Get all projects
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        //3. Loop through EM instances
        while($proj = $enabled->fetch_assoc()) {

            //2. Load configs
            $pid = $proj['project_id'];
            //check that current hour is time to send
            $scheduled_hour = $this->getProjectSetting('send-time', $pid);
            $current_hour = date('H');

            //iterate through all the sub settings
            foreach ($scheduled_hour as $sub => $invite_time) {
                //check that the greeting is enabled
                $enabled_greeting = $this->getProjectSetting('enable-greeting', $pid)[$sub];
                if ($enabled_greeting == '1') {

                    //if not hour, continue
                    if ($invite_time != $current_hour) continue;

                    //get the subsettings
                    $subsettings = array_filter($this->getSubSettings('greeting', $pid));

                    //send greetings
                    try {
                        $this->sendGreeting($pid, $subsettings[$sub]);
                    } catch (EMConfigurationException $ece) {
                        $this->emError($ece->getMessage());
                        REDCap::logEvent(
                            "EM Config Error in BirthdayGreeting EM ",  //action
                            $ece->getMessage(),
                            null, null, null, $pid
                        );
                        break;
                    }
                } else {
                    $this->emDebug("BirthdayGreeting not enabled for $pid with sub $sub");
                }

            }


        }

    }

    /**
    [0] => Array
    (
    [enable-greeting] => 1
    [template-title] => TEMPLATE_BirthdayGreeting
    [birthday-field] => birthday
    [birthday-field-event-name] => event_1_arm_1
    [send-time] => 14
    [sent-ts-field] => sent_timestamp
    [stop-logic-field] =>
    )
     *
     * @param $pid
     * @param $subsettings
     */
    public function sendGreeting($pid, $subsettings) {
        //set project context for this pid; this does not work
        $original_pid = $_GET['pid'];
        $_GET['pid']  = $pid;

        $proj         = new Project($pid);

        //REDCap methods don't work event with faked project context?
        //rec_id_field = REDCap::getRecordIdField();
        $rec_id_field = $proj->table_pk;

        $this->emDebug("Sending Greetings for $pid");
        //$this->emDebug(" with config", $subsettings);

        $template_title = $subsettings['template-title'];
        $event_id       = $subsettings['birthday-field-event-name'];
        $stop_logic     = $subsettings['stop-logic-field'];
        $ts_field       = $subsettings['sent-ts-field'];

        //check EM config
        if (empty($template_title) || (empty($subsettings['birthday-field']) )) {
            throw new EMConfigurationException('Alert EM not set correctly.');
        }

        //get the data for this project
        $redcap_fields = array(
            $rec_id_field,
            $subsettings['birthday-field'],
            $subsettings['sent-ts-field']
        );

        $params = array(
            'project_id'    => $pid,
            'return_format' => 'json',
            'fields'        => $redcap_fields,
            'events'        => $event_id
        );

        //this does not work???
        //$q = REDCap::getData($pid, $params);
        //$bday_data = json_decode($q, true); //this is null!?

        $s = REDCap::getData($pid, 'json', null, $redcap_fields, $event_id);
        $bday_data = json_decode($s, true);

        $today = new DateTime();

        //iterate through each record in this project and check birthday
        foreach ($bday_data as $k=> $v) {
            $record_id = $v[$rec_id_field];

            //check record fields
            if ((empty($v[$subsettings['birthday-field']]))) {
                $msg = "Missing birthday in record $record_id";
                $this->emError($msg);
                REDCap::logEvent(
                    "Error in BirthdayGreeting EM ",  //action
                    $msg,
                    null, null, null, $pid
                );
                continue;
            }

            //check if it is the birthday today
            $birthday = new DateTime($v[$subsettings['birthday-field']]);

            $this->emDebug("Record id field is $rec_id_field and record id is $record_id and bday is ".$birthday->format('Y-m-d') );

            if ($birthday->format("m-d") == $today->format("m-d")) {
                //today is the birthday

                //make sure from timestamp that it has not already been sent.
                if (isset($ts_field)) {
                    $timestamp = empty($v[$ts_field]) ? null : new DateTime($v[$ts_field]);

                    if (isset($timestamp) && ($timestamp->format('Y-m-d')) == ($today->format('Y-m-d'))) {
                        $this->emDebug("Alert has already been sent today for record id $record_id.  Not sending any more emails");
                        continue;
                    }
                }

                //check logic
                if (isset($stop_logic)) {
                    $this->emDebug("evaluating logic for record $record_id". $stop_logic);
                    $logic_result = REDCap::evaluateLogic($stop_logic, $pid, $record_id, $event_id);
                    if ($logic_result == false) {
                        //logic failed for this candidate
                        $this->emDebug("$stop_logic failed for record  $record_id. Not sending.");
                        continue;
                    }
                }

                //Since not handling repeating instruments, just send null for repeats.
                $repeat_instance = null;
                $instrument = null;

                //send alert
                try {
                    $status = $this->sendTemplateAlert($pid, $record_id, $event_id, $repeat_instance, $instrument, $template_title);
                } catch (EMConfigurationException $ece) {
                    $this->emError("Alert not found. Check the EM or the Alerts");
                    //log event
                    //$description, $changes_made="", $sql="", $record=null, $event_id=null, $project_id=null
                    REDCap::logEvent(
                        "EM Config Error in BirthdayGreeting EM ",  //action
                        "The Alert, $template_title, could not be found. Please check both the EM configuration and the Alerts.",
                        NULL, //sql optional
                        $record_id,//record optional
                        $event_id,
                        $pid
                    );
                }

                if ($status) {
                    //log event
                    REDCap::logEvent(
                        "Email sent from BirthdayGreeting EM ",  //action
                        "Birthday greeting was sent for $record_id using $template_title .",
                        NULL, //sql optional
                        $record_id,//record optional
                        $event_id,
                        $pid
                    );


                    //save timestamp
                    if (isset($ts_field)) {
                        $save_ts_data = array(
                            'record_id'         => $record_id,
                            'redcap_event_name' => $proj->getUniqueEventNames($event_id), //REDCap::getEventNames(true, false, $event_id),
                            $ts_field           => $today->format('Y-m-d')
                        );
                        $ts_status = REDCap::saveData($pid, 'json', json_encode(array($save_ts_data)));
                    }

                } else {
                    //todo log fail event
                    REDCap::logEvent(
                        "Email fail from BirthdayGreeting EM ",  //action
                        "Birthday greeting was unable to be sent for $record_id using $template_title .",
                        NULL, //sql optional
                        $record_id,//record optional
                        $event_id,
                        $pid
                    );

                    $this->emError("unable to send notification");
                }

            }

        }

        //set pid back
        $_GET['pid']  = $original_pid;
        return;


    }


    /**
     *
     *
     * @param $record
     * @param $event_id - event_id where the check_date_field is located
     * @param $repeat_instance - repeat_instance number where the check_date_field
     * @param $instrument
     * @param $alert_title
     */
    public function sendTemplateAlert($pid, $record, $event_id, $repeat_instance=null, $instrument=null, $alert_title) {
        $this->emDebug("SEnding $alert_title for Record $record");


        $alerts = new Alerts();
        $project_alerts = $alerts->getAlertSettings($pid);

        $alert_id = array_search($alert_title, array_combine(array_keys($project_alerts), array_column($project_alerts, 'alert_title')));

        if (empty($alert_id)) {
            throw new EMConfigurationException('Alert not found.');
        }

        $this->emDebug("Found alert title $alert_title sending notification alertid $alert_id to $record");
        //send alert
        $status =        $alerts->sendNotification($alert_id,$pid, $record, $event_id, $instrument);
        $this->emDebug($status);
        return $status;

    }



}
