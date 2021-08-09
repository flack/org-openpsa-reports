<?php
/**
 * @package org.openpsa.reports
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Deliverable reports
 *
 * @package org.openpsa.reports
 */
class org_openpsa_reports_handler_projects_report extends org_openpsa_reports_handler_base
{
    private $_grouping = 'date';
    private $_valid_groupings = [
        'date' => true,
        'person' => true,
    ];

    private $raw_results;

    public function _on_initialize()
    {
        $this->module = 'projects';
    }

    /**
     * Get array of IDs of all tasks in subtree
     */
    private function _expand_task(string $project_guid) : array
    {
        $project = org_openpsa_projects_project::get_cached($project_guid);
        $mc = org_openpsa_projects_task_dba::new_collector();
        $mc->add_constraint('project', '=', $project->id);
        return $mc->get_values('id');
    }

    /**
     * Makes and executes querybuilder for filtering hour_reports
     *
     * @return org_openpsa_expenses_hour_report_dba[]
     */
    private function _get_hour_reports(array &$query_data) : array
    {
        $qb_hr = org_openpsa_expenses_hour_report_dba::new_query_builder();
        $qb_hr->add_constraint('date', '<=', (int) $query_data['end']);
        $qb_hr->add_constraint('date', '>=', (int) $query_data['start']);
        if (   array_key_exists('invoiceable_filter', $query_data)
            && $query_data['invoiceable_filter'] != -1) {
            $qb_hr->add_constraint('invoiceable', '=', (bool) $query_data['invoiceable_filter']);
        }

        if (   array_key_exists('invoiced_filter', $query_data)
            && $query_data['invoiced_filter'] != -1) {
            if ((int) $query_data['invoiced_filter']) {
                debug_add('invoiced_filter parsed as ONLY, adding constraint');
                $qb_hr->add_constraint('invoice', '<>', 0);
            } else {
                debug_add('invoiced_filter parsed as only NOT, adding constraint');
                $qb_hr->add_constraint('invoice', '=', 0);
            }
        }

        if ($query_data['resource'] != 'all') {
            $query_data['resource_expanded'] = $this->_expand_resource($query_data['resource']);
            $qb_hr->add_constraint('person', 'IN', $query_data['resource_expanded']);
        }
        if ($query_data['task'] != 'all') {
            $tasks = $this->_expand_task($query_data['task']);
            $qb_hr->add_constraint('task', 'IN', $tasks);
        }
        if (   array_key_exists('hour_type_filter', $query_data)
            && $query_data['hour_type_filter'] != 'builtin:all') {
            $qb_hr->add_constraint('reportType', '=', $query_data['hour_type_filter']);
        }
        return $qb_hr->execute();
    }

    private function _sort_rows()
    {
        usort($this->_request_data['report']['rows'], ['self', '_sort_by_key']);
        foreach ($this->_request_data['report']['rows'] as &$group) {
            if (!empty($group['rows'])) {
                usort($group['rows'], ['self', '_sort_by_key']);
            }
        }
    }

    private static function _sort_by_key($a, $b)
    {
        $ap = $a['sort'];
        $bp = $b['sort'];
        if (is_numeric($ap)) {
            return $ap <=> $bp;
        }
        if (is_string($ap)) {
            return strnatcmp($ap, $bp);
        }
        return 0;
    }

    private function _analyze_raw_hours()
    {
        if (empty($this->raw_results['hr'])) {
            debug_add('Hour reports array not found', MIDCOM_LOG_WARN);
            return;
        }
        $formatter = $this->_l10n->get_formatter();
        foreach ($this->raw_results['hr'] as $hour) {
            $row = [];
            try {
                $row['person'] = org_openpsa_contacts_person_dba::get_cached($hour->person);
            } catch (midcom_error $e) {
                $e->log();
                continue;
            }
            $row['hour'] = $hour;
            $row['task'] = org_openpsa_projects_task_dba::get_cached($hour->task);

            // Default (should work for almost every grouping) is to sort rows by the hour report date
            $row['sort'] = $row['hour']->date;
            //Determine our group
            if ($this->_grouping == 'date') {
                $matching = 'date:' . date('Ymd', $row['hour']->date);
                $sort = date('Ymd', $row['hour']->date);
                $title = $formatter->date($row['hour']->date);
            } else {
                $matching = 'person:' . $row['person']->guid;
                $sort = $row['person']->rname;
                $title = $row['person']->rname;
            }
            $this->add_to_group($row, $matching, $sort, $title);

            //Place data to report
            $this->_request_data['report']['total_hours'] += $hour->hours;
        }
    }

    private function add_to_group(array $new_row, string $matching, string $sort, string $title)
    {
        $rows =& $this->_request_data['report']['rows'];
        if (array_key_exists($matching, $rows)) {
            $rows[$matching]['rows'][] = $new_row;
            $rows[$matching]['total_hours'] += $new_row['hour']->hours;
        } else {
            $rows[$matching] = [
                'sort' => $sort,
                'title' => $title,
                'rows' => [$new_row],
                'total_hours' => $new_row['hour']->hours
            ];
        }
    }

    public function _show_generator(string $handler_id, array &$data)
    {
        //Mangling if report wants to do it (done here to have style context, otherwise MidCOM will not like us.
        midcom_show_style('projects_report-basic-mangle-query');
        //Handle grouping
        if (!empty($data['query_data']['grouping'])) {
            if (array_key_exists($data['query_data']['grouping'], $this->_valid_groupings)) {
                debug_add('Setting grouping to: ' . $data['query_data']['grouping']);
                $this->_grouping = $data['query_data']['grouping'];
            } else {
                debug_add(sprintf("\"%s\" is not a valid grouping, keeping default", $data['query_data']['grouping']), MIDCOM_LOG_WARN);
            }
        }

        // Put grouping to request data
        $data['grouping'] = $this->_grouping;

        //Get our results
        $results_hr = $this->_get_hour_reports($data['query_data']);

        //For debugging and sensible passing of data
        $this->raw_results = ['hr' => $results_hr];
        //TODO: Mileages, expenses

        $data['report'] = ['rows' => [], 'total_hours' => 0];

        $this->_analyze_raw_hours();
        $this->_sort_rows();

        //TODO: add other report types when supported
        if (empty($this->raw_results['hr'])) {
            midcom_show_style('projects_report-basic-noresults');
            return;
        }

        //Start actual display

        //Indented to make style flow clearer
        midcom_show_style('projects_report-basic-start');
        midcom_show_style('projects_report-basic-header');
        $this->_show_generator_group($data['report']['rows']);
        midcom_show_style('projects_report-basic-totals');
        midcom_show_style('projects_report-basic-footer');
        midcom_show_style('projects_report-basic-end');
    }

    public function _show_generator_group(array $data)
    {
        foreach ($data as $group) {
            $this->_request_data['current_group'] = $group;
            //Indented to make style flow clearer
            midcom_show_style('projects_report-basic-group-header');
            foreach ($group['rows'] as $row) {
                $this->_request_data['current_row'] = $row;
                midcom_show_style('projects_report-basic-item');
            }
            midcom_show_style('projects_report-basic-group-totals');
            midcom_show_style('projects_report-basic-group-footer');
        }
    }
}
