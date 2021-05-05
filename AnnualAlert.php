<?php
namespace Stanford\AnnualAlert;

require_once "emLoggerTrait.php";
require_once "EMConfigurationException.php";

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use \REDCap;
use Project;
use Alerts;
use DateTime;
use Stanford\AnnualAlert\EMConfigurationException as EMConfigurationException;


class AnnualAlert extends AbstractExternalModule {

    use emLoggerTrait;

    private $alerts;


    /*******************************************************************************************************************/
    /* CRON METHODS                                                                                                    */
    /***************************************************************************************************************** */

    public function cronGreeting() {

        $this->emDebug("Starting send cron for birthday greetings " . $this->PREFIX);


        //1. Get all projects
        //$enabled = ExternalModules::getEnabledProjects($this->PREFIX);
        $enabled_projects = $this->getProjectsWithModuleEnabled();

        $current_hour = date('H');
        //3. Loop through EM instances
        //while($proj = $enabled->fetch_assoc()) {
        foreach ($enabled_projects as $project_id) {
            $this->emDebug("Starting annual alert check for $project_id");

            //2. Load configs
            $instances = $this->getSubSettings('greeting', $project_id);

            //iterate through each instance
            foreach ($instances as $i => $instance) {
                // Make sure it is enabled
                if ($instance['enable-greeting'] !== true) continue;

                // Make sure the hour is correct
                if ((int)$instance['send-time'] !== (int)$current_hour) continue;

                //send greetings
                try {
                    $this->sendGreeting($project_id, $instance);
                }
                catch (EMConfigurationException $ece) {
                    $this->emError($ece->getMessage());
                    REDCap::logEvent(
                        "EM Config Error in AnnualAlert EM ",  //action
                        $ece->getMessage(),
                        null, null, null, $project_id
                    );
                }
            }
        }
    }

    /**
    [0] => Array
    (
    [enable-greeting] => 1
    [template-title] => TEMPLATE_AnnualAlert
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
    public function sendGreeting($project_id, $instance) {

        $proj         = new Project($project_id);
        $rec_id_field = $proj->table_pk;

        $this->emDebug("Sending Greetings for $project_id");
        //$this->emDebug(" with config", $instance);

        $template_title = $instance['template-title'];
        $birthday_field = $instance['birthday-field'];
        $event_id       = $instance['birthday-field-event-name'];
        $stop_logic     = $instance['stop-logic-field'];
        $ts_field       = $instance['sent-ts-field'];

        //check EM config
        if (empty($template_title) || empty($birthday_field)) {
            throw new EMConfigurationException('Alert EM not set correctly.');
        }

        //get the data for this project
        $redcap_fields = array(
            $rec_id_field,
            $birthday_field,
            $ts_field
        );

        $params = array(
            'project_id'    => $project_id,
            'return_format' => 'json',
            'fields'        => $redcap_fields,
            'events'        => $event_id
        );

        //this does not work???
        //$q = REDCap::getData($pid, $params);
        //$bday_data = json_decode($q, true); //this is null!?

        $s = REDCap::getData($project_id, 'json', null, $redcap_fields, $event_id);
        $bday_data = json_decode($s, true);

        if (!empty($s['errors'])) {
            throw new EMConfigurationException(json_encode($s['errors']));
        }

        $today = new DateTime();

        //iterate through each record in this project and check birthday
        foreach ($bday_data as $v) {
            $record_id = $v[$rec_id_field];

            //check record fields
            if ((empty($v[$birthday_field]))) {
                $msg = "Missing birthday in record $record_id";
                $this->emDebug($msg);
                continue;
            }

            //check if it is the birthday today
            $birthday = new DateTime($v[$birthday_field]);

            $this->emDebug("Record id field is $rec_id_field and record id is $record_id and bday is ".$birthday->format('Y-m-d') );

            if ($birthday->format("m-d") == $today->format("m-d")) {
                //today is the birthday

                //make sure from timestamp that it has not already been sent.
                if (!empty($ts_field) && !empty($v[$ts_field])) {
                    $ts_sent = new DateTime($v[$ts_field]);

                    if ($ts_sent->format('Y-m-d') == $today->format('Y-m-d')) {
                        $this->emDebug("Alert has already been sent today for record id $record_id.  Not sending any more emails");
                        continue;
                    }
                }

                //check logic
                if (!empty($stop_logic)) {
                    $this->emDebug("Evaluating logic for record $record_id - $stop_logic");
                    $logic_result = REDCap::evaluateLogic($stop_logic, $project_id, $record_id, $event_id);
                    if ($logic_result == false) {
                        //logic failed for this candidate
                        $this->emDebug("$stop_logic failed for record $record_id. Not sending.");
                        continue;
                    }
                }

                //Since not handling repeating instruments, just send null for repeats.
                $repeat_instance = null;
                $instrument = null;

                //send alert
                try {
                    $status = $this->sendTemplateAlert($project_id, $record_id, $event_id, $repeat_instance, $instrument, $template_title);
                } catch (EMConfigurationException $ece) {
                    $this->emError("Alert not found. Check the EM or the Alerts");
                    //log event
                    //$description, $changes_made="", $sql="", $record=null, $event_id=null, $project_id=null
                    REDCap::logEvent(
                        "EM Config Error in AnnualAlert EM ",  //action
                        "The Alert, $template_title, could not be found. Please check both the EM configuration and the Alerts.",
                        NULL, //sql optional
                        $record_id,//record optional
                        $event_id,
                        $project_id
                    );
                }

                if ($status) {
                    //log event
                    REDCap::logEvent(
                        "Email sent from AnnualAlert EM ",  //action
                        "Birthday greeting was sent for $record_id using $template_title",
                        NULL, //sql optional
                        $record_id,//record optional
                        $event_id,
                        $project_id
                    );


                    //save timestamp
                    if (!empty($ts_field)) {
                        $save_ts_data = array(
                            'record_id'         => $record_id,
                            'redcap_event_name' => $proj->getUniqueEventNames($event_id), //REDCap::getEventNames(true, false, $event_id),
                            $ts_field           => $today->format('Y-m-d')
                        );
                        $q = REDCap::saveData($project_id, 'json', json_encode(array($save_ts_data)));

                        //todo save as array
                        //
                        if (!empty($q['errors'])) {
                            $this->emError("Error saving timestamp for record $record_id with value: $ts_field", $q);
                        }
                    }

                } else {
                    //log fail event
                    REDCap::logEvent(
                        "Email fail from AnnualAlert EM ",  //action
                        "Birthday greeting was unable to be sent for $record_id using $template_title .",
                        NULL, //sql optional
                        $record_id,//record optional
                        $event_id,
                        $project_id
                    );
                    $this->emError("unable to send notification for $record_id in $project_id using $template_title");
                }
            }
        }
    }


    /**
     * This method hijacks the Alerts and Notifications feature to control the body of the message
     *
     * @param $record
     * @param $event_id - event_id where the check_date_field is located
     * @param $repeat_instance - repeat_instance number where the check_date_field
     * @param $instrument
     * @param $alert_title
     */
    public function sendTemplateAlert($project_id, $record, $event_id, $repeat_instance=null, $instrument=null, $alert_title) {
        $this->emDebug("Sending $alert_title for Record $record");

        $alerts = $this->getAlerts();

        //$alerts = new Alerts();
        $project_alerts = $alerts->getAlertSettings($project_id);

        // $project_alerts is an array with key of alert_id and value of alert settings
        // Creating an array of alert_id => alert_title for searching
        $alert_id = array_search($alert_title, array_combine(array_keys($project_alerts), array_column($project_alerts, 'alert_title')));

        if (empty($alert_id)) {
            throw new EMConfigurationException("Alert $alert_title not found in project $project_id - verify alert title is correct");
        }

        //send alert
        $status = $alerts->sendNotification($alert_id,$project_id, $record, $event_id, $instrument);
        $this->emDebug("Found alert $alert_title ($alert_id) - sending for record $record with result:" . json_encode($status));
        return $status;
    }


    /*******************************************************************************************************************/
    /* GETTER/SETTER METHODS                                                                                           */
    /***************************************************************************************************************** */

    public function getAlerts() {
        if (empty($this->alerts)) {
            $this->alerts = new Alerts();
        }

        return $this->alerts;
    }

    /*******************************************************************************************************************/
    /* HOOK METHODS                                                                                                     */
    /***************************************************************************************************************** */
    function redcap_every_page_top($project_id = null) {

        // ONLY DO STUFF FOR THE ONLINE DESIGNER PAGE:
        if (PAGE == "Design/online_designer.php") {
            $this->emDebug("Calling hook_every_page_top on " . PAGE);

            // Apparently the config usn't loaded?  TODO: TEST THIS.

            $bday_fields = $this->getProjectSetting('birthday-field');
            $ts_field = $this->getProjectSetting('sent-ts-field');


            // Highlight shazam fields on the page
            ?>

            <script>
                $(document).ready(function () {
                    var bday_field = <?php echo json_encode(current($bday_fields)); ?>;
                    var ts_field = <?php echo json_encode(current($ts_field)); ?>;
                    var fields = new Array(bday_field, ts_field);

                    $(fields).each( function(i,e){
                        var tr = $('tr[sq_id="' + e + '"]');
                        if (tr.length) {
                            var icon_div = $('.frmedit_icons', tr);
                            var label = $('<span>AnnualAlert</span>')
                                .addClass("badge badge-info annual-label")
                                .attr("data-toggle", "tooltip")
                                .attr("title", "The content of this field is generated by the AnnualAlert External Module")
                                .on('click', function() {
                                    event.stopPropagation();
                                })
                                .appendTo(icon_div);
                            console.log("Highlight Fields", e);
                        }
                    });

                });


            </script>
            <style>
                .annual-label {
                    z-index: 1000;
                    float: right;
                    padding: 3px;
                    margin-right: 10px;
                }

                .annual-label:hover {
                    cursor: pointer;
                }

            </style>
            <?php
        }

    }



}
