<?php
/**
 * @file
 * Provides ExternalModule class for Linear Data Entry Workflow.
 */

namespace LinearDataEntryWorkflow\ExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

/**
 * ExternalModule class for Linear Data Entry Workflow.
 */
class ExternalModule extends AbstractExternalModule {

    static protected $deniedForms;

    /**
     * @inheritdoc
     */
    function hook_every_page_top($project_id) {
        if (!$project_id) {
            return;
        }

        // Initializing settings JS variable.
        echo '<script>var linearDataEntryWorkflow = {};</script>';

        switch (PAGE) {
            case 'DataEntry/record_home.php':
                $args_order = array('pid', 'id', 'event_id', 'page');
                break;

            case 'DataEntry/record_status_dashboard.php':
                $args_order = array('pid', 'id', 'page', 'event_id', 'instance');
                break;

            default:
                return;

        }

        $this->loadBulletsHandler($args_order, $this->getNumericQueryParam('arm', 1), $this->getNumericQueryParam('id'));
    }

    /**
     * @inheritdoc
     */
    function hook_data_entry_form($project_id, $record = null, $instrument, $event_id, $group_id = null) {
        global $Proj;

        if (!$record) {
            $record = $this->getNumericQueryParam('id');
        }

        $this->loadBulletsHandler(array('pid', 'page', 'id', 'event_id'), $Proj->eventInfo[$event_id]['arm_num'], $record, $event_id, $form);
        $this->loadButtonsHandler($record, $event_id, $form);

        if (($exceptions = $this->getProjectSetting('forms-exceptions', $project_id)) && in_array($instrument, $exceptions)) {
            return;
        }

        $this->loadFDEC($instrument);
        $this->loadAutoLock($project_id, $instrument);
    }

    function loadBulletsHandler($args_order, $arm, $record = null, $event_id = null, $form = null) {
        $args = array_combine($args_order, array_fill(0, count($args_order), '1'));
        $args['pid'] = PROJECT_ID;

        $selectors = array();
        foreach ($this->getDeniedForms($arm, $record, $event_id, $form) as $id => $events) {
            $args['id'] = $id;

            foreach ($events as $event_id => $forms) {
                $args['event_id'] = $event_id;

                foreach ($forms as $page) {
                    $args['page'] = $page;
                    $selectors[] = 'a[href="' . APP_PATH_WEBROOT . 'DataEntry/index.php?' . http_build_query($args) . '"]';
                }
            }
        }

        if (!empty($selectors)) {
            echo '<style>' . implode(', ', $selectors) . ' { opacity: .1; pointer-events: none; }</style>';
        }
    }

    function loadButtonsHandler($record, $event_id, $form) {
        global $Proj;

        if (!$exceptions = $this->getProjectSetting('forms-exceptions', $Proj->project_id)) {
            $exceptions = array();
        }

        $settings = array(
            'instrument' => $instrument,
            'isException' => in_array($instrument, $exceptions),
            'forceButtonsDisplay' => $Proj->lastFormName == $instrument ? 'show' : false,
            'hideNextRecordButton' => $this->getProjectSetting('hide-next-record-button', $Proj->project_id),
        );

        if (!$settings['forceButtonsDisplay']) {
            $i = array_search($instrument, $Proj->eventsForms[$event_id]);
            $next_form = $Proj->eventsForms[$event_id][$i + 1];

            if (in_array($next_form, $exceptions)) {
                // Handling the case where the next form is an exception,
                // so we need to show the buttons no matter the form status.
                $settings['forceButtonsDisplay'] = 'show';
            }
            elseif ($settings['isException']) {
                // Handling 2 cases for exception forms:
                // - Case A: the next form is not accessible, so we need to keep
                //   the buttons hidden, no matter if form gets shifted to
                //   Complete status.
                // - Case B: the next form is accessible, so we need to keep the
                //   buttons visible, no matter if form gets shifted to a non
                //   Completed status.

                $settings['forceButtonsDisplay'] = 'hide';
                if (empty(self::$deniedForms[$record][$event_id][$next_form])) {
                    $settings['forceButtonsDisplay'] = 'show';

                    // Handling possible conflicts with CTSIT's Form Render Skip Logic.
                    if (defined('FORM_RENDER_SKIP_LOGIC_PREFIX')) {
                        $arm = $Proj->eventInfo[$event_id]['arm_num'];
                        $denied_forms = ExternalModules::getModuleInstance(FORM_RENDER_SKIP_LOGIC_PREFIX)->getDeniedForms($arm, $record);

                        if (!empty($deniedForms[$record][$event_id][$next_form])) {
                            $settings['forceButtonsDisplay'] = 'hide';
                        }
                    }
                }
            }
        }

        $this->setJsSetting('rfio', $settings);
        $this->includeJs('js/rfio.js');
    }

    /**
     * Loads forms access matrix.
     *
     * @param string $arm
     *   The arm name.
     * @param int $record
     *   The data entry record ID.
     * @param int $event_id
     *   The event ID. Only required when $location = "data_entry_form".
     * @param string $instrument
     *   The form/instrument name.
     */
    function getDeniedForms($arm, $record = null, $event_id = null, $instrument = null) {
        if (isset(self::$deniedForms)) {
            return self::$deniedForms;
        }

        // Proj is a REDCap var used to pass information about the current project.
        global $Proj;

        // Use form names to contruct complete_status field names.
        $fields = array();
        foreach (array_keys($Proj->forms) as $form_name) {
            $fields[$form_name] = $form_name . '_complete';
        }

        $completed_forms = REDCap::getData($Proj->project_id, 'array', $record, $fields);
        if ($record && !isset($completed_forms[$record])) {
            // Handling new record case.
            $completed_forms = array($record => array());
        }

        if (!$exceptions = $this->getProjectSetting('forms-exceptions', $Proj->project_id)) {
            $exceptions = array();
        }

        // Building forms access matrix.
        $denied_forms = array();

        // Handling possible conflicts with CTSIT's Form Render Skip Logic.
        if (defined('FORM_RENDER_SKIP_LOGIC_PREFIX')) {
            $denied_forms = ExternalModules::getModuleInstance(FORM_RENDER_SKIP_LOGIC_PREFIX)->getDeniedForms($arm, $record);
        }

        foreach ($completed_forms as $id => $data) {
            if (!isset($denied_forms[$id])) {
                $denied_forms[$id] = array();
            }

            $prev_form_completed = true;

            foreach (array_keys($Proj->events[$arm]['events']) as $event) {
                if (!isset($denied_forms[$id][$event])) {
                    $denied_forms[$id][$event] = array();
                }

                foreach ($Proj->eventsForms[$event] as $form) {
                    if (!empty($denied_forms[$id][$event][$form]) || in_array($form, $exceptions)) {
                        continue;
                    }

                    if (!$prev_form_completed) {
                        if ($id == $record && $event == $event_id && $instrument == $form) {
                            // Access denied to the current page.
                            $this->redirect(APP_PATH_WEBROOT . 'DataEntry/record_home.php?pid=' . $Proj->project_id . '&id=' . $record . '&arm=' . $arm);
                            return false;
                        }

                        $denied_forms[$id][$event][$form] = $form;
                        continue;
                    }

                    if (empty($data['repeat_instances'][$event][$form])) {
                        $prev_form_completed = !empty($data[$event][$fields[$form]]) && $data[$event][$fields[$form]] == 2;
                        continue;
                    }

                    // Repeat instances case.
                    foreach ($data['repeat_instances'][$event][$form] as $instance) {
                        if (empty($instance[$fields[$form]]) || $instance[$fields[$form]] != 2) {
                            // Block access to next instrument if an instance is
                            // not completed.
                            $prev_form_completed = false;
                            break;
                        }
                    }
                }
            }
        }

        self::$deniedForms = $denied_forms;
        return $denied_forms;
    }

    /**
     * Loads FDEC (force data entry constraints) feature.
     *
     * @param string $instrument
     *   The instrument/form ID.
     * (optional) @param array $statuses_bypass
     *   An array of form statuses to bypass FDEC. Possible statuses:
     *   - 0 (Incomplete)
     *   - 1 (Unverified)
     *   - 2 (Completed)
     *   - "" (Empty status)
     */
    protected function loadFDEC($instrument, $statuses_bypass = array('', 0, 1)) {
        global $Proj;

        // Markup of required fields bullets list.
        $bullets = '';

        // Selectors to search for empty required fields.
        $req_fields_selectors = array();

        // Getting required fields from form config.
        foreach (array_keys($Proj->forms[$instrument]['fields']) as $field_name) {
            $field_info = $Proj->metadata[$field_name];
            if (!$field_info['field_req']) {
                continue;
            }

            // The bullets are hidden for default, since we do not know which ones will be empty.
            $field_label = filter_tags(label_decode($field_info['element_label']));
            $bullets .= '<div class="req-bullet req-bullet--' . $field_name . '" style="margin-left: 1.5em; text-indent: -1em; display: none;"> &bull; ' . $field_label . '</div>';

            $req_fields_selectors[] = '#questiontable ' . ($field_info['element_type'] == 'select' ? 'select' : 'input') . '[name="' . $field_name . '"]:visible';
        }

        // Printing required fields popup (hidden yet).
        echo '
            <div id="preemptiveReqPopup" title="Some fields are required!" style="display:none;text-align:left;">
                <p>You did not provide a value for some fields that require a value. Please enter a value for the fields on this page that are listed below.</p>
                <div style="font-size:11px; font-family: tahoma, arial; font-weight: bold; padding: 3px 0;">' . $bullets . '</div>
            </div>';

        $settings = array(
            'statusesBypass' => array_map(function($value) { return (string) $value; }, $statuses_bypass),
            'requiredFieldsSelector' => implode(',', $req_fields_selectors),
            'instrument' => $instrument,
        );

        $this->setJsSetting('fdec', $settings);
        $this->includeJs('js/fdec.js');
    }

    /**
     * Loads auto-lock feature.
     */
    protected function loadAutoLock($project_id, $instrument) {
        if (!$roles_to_lock = $this->getProjectSetting('auto-locked-roles', $Proj->project_id)) {
            return;
        }

        global $user_rights;

        // Load auto-lock script if user is in an auto-locked role.
        if (in_array($user_rights['role_id'], $roles_to_lock)) {
            $this->includeJs('js/auto-lock.js');
        }
    }

    /**
     * Redirects user to the given URL.
     *
     * This function basically replicates redirect() function, but since EM
     * throws an error when an exit() is called, we need to adapt it to the
     * EM way of exiting.
     */
    protected function redirect($url) {
        if (headers_sent()) {
            // If contents already output, use javascript to redirect instead.
            echo '<script>window.location.href="' . $url . '";</script>';
        }
        else {
            // Redirect using PHP.
            header('Location: ' . $url);
        }

        $this->exitAfterHook();
    }

    /**
     * Includes a local JS file.
     *
     * @param string $path
     *   The relative path to the js file.
     */
    protected function includeJs($path) {
        echo '<script src="' . $this->getUrl($path) . '"></script>';
    }

    /**
     * Sets a JS setting.
     *
     * @param string $key
     *   The setting key to be appended to the module settings object.
     * @param mixed $value
     *   The setting value.
     */
    protected function setJsSetting($key, $value) {
        echo '<script>linearDataEntryWorkflow.' . $key . ' = ' . json_encode($value) . ';</script>';
    }

    /**
     * Gets numeric URL query parameter.
     *
     * @param string $param
     *   The parameter name
     * @param mixed $default
     *   The default value if query parameter is not available.
     *
     * @return mixed
     *   The parameter from URL if available. The default value provided is
     *   returned otherwise.
     */
    function getNumericQueryParam($param, $default = null) {
        return empty($_GET[$param]) || intval($_GET[$param]) != $_GET[$param] ? $default : $_GET[$param];
    }
}
