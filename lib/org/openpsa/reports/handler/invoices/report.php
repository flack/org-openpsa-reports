<?php
/**
 * @package org.openpsa.reports
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use Symfony\Component\HttpFoundation\Request;

/**
 * Invoices reporting
 *
 * @package org.openpsa.reports
 */
class org_openpsa_reports_handler_invoices_report extends org_openpsa_reports_handler_base
{
    private $_sales_url;

    public function _on_initialize()
    {
        $this->module = 'invoices';
    }

    public function _handler_generator(array $args, array &$data)
    {
        if ($response = parent::_handler_generator($args, $data)) {
            return $response;
        }
        $this->process_handler($data);
    }

    public function _handler_generator_get(Request $request, array &$data)
    {
        parent::_handler_generator_get($request, $data);
        $this->process_handler($data);
    }

    private function process_handler(array &$data)
    {
        $data['start'] = $data['query_data']['start'];
        $data['end'] = $data['query_data']['end'];

        if (empty($data['query_data']['date_field'])) {
            $data['query_data']['date_field'] = $data['query']->get_parameter('midcom.helper.datamanager2', 'date_field');
        }
        $data['date_field'] = $data['query_data']['date_field'];

        $data['invoices'] = [];
        foreach ($data['query_data']['invoice_status'] as $status) {
            $data['invoices'] = array_merge($data['invoices'], $this->_load_invoices($status));
        }

        midcom::get()->head->add_jsfile(MIDCOM_STATIC_URL . '/midcom.grid/FileSaver.js');
        midcom::get()->head->add_jsfile(MIDCOM_STATIC_URL . '/' . $this->_component . '/chartjs-4.2.1/chart.umd.js');
        midcom::get()->head->add_jsfile(MIDCOM_STATIC_URL . '/' . $this->_component . '/chart.js');
    }

    private function _get_invoices_for_subscription(org_openpsa_sales_salesproject_deliverable_dba $deliverable, midcom_services_at_entry_dba $at_entry) : array
    {
        if (   $deliverable->invoiceByActualUnits
            && $at_entry->arguments['cycle'] > 1) {
            $months = midcom_baseclasses_components_configuration::get('org.openpsa.sales', 'config')->get('subscription_profit_months');
            $cutoff = (new DateTime)->modify('-' . $months . ' months');
            $qb = org_openpsa_invoices_invoice_item_dba::new_query_builder();
            $qb->add_constraint('deliverable', '=', $deliverable->id);
            $qb->add_constraint('metadata.created', '>=', $cutoff->format('Y-m-d'));
            $runs = $invoice_sum = 0;
            foreach ($qb->execute() as $item) {
                $runs++;
                $invoice_sum += $item->pricePerUnit * $item->units;
            }
            if ($invoice_sum == 0) {
                return [];
            }
            $invoice_sum /= $runs;
            $calculation_base = sprintf($this->_l10n->get('average of %s runs'), $runs);
        } else {
            $invoice_sum = $deliverable->price;
            $calculation_base = $this->_l10n->get('fixed price');
        }

        $invoices = [];
        $time = $at_entry->start;
        $scheduler = new org_openpsa_invoices_scheduler($deliverable);

        while (   $time < $this->_request_data['end']
               && (   $time < $deliverable->end
                   || $deliverable->continuous)) {
            $invoices[] = $this->get_invoice_for_deliverable($deliverable, $invoice_sum, $time, $calculation_base);

            if (!$time = $scheduler->calculate_cycle_next($time)) {
                debug_add('Failed to calculate timestamp for next cycle, exiting', MIDCOM_LOG_WARN);
                break;
            }
        }

        return $invoices;
    }

    private function get_invoice_for_deliverable(org_openpsa_sales_salesproject_deliverable_dba $deliverable, $sum, int $time, string $calculation_base) : org_openpsa_invoices_invoice_dba
    {
        $salesproject = org_openpsa_sales_salesproject_dba::get_cached($deliverable->salesproject);
        $invoice = new org_openpsa_invoices_invoice_dba;
        $invoice->customer = $salesproject->customer;
        $invoice->customerContact = $salesproject->customerContact;
        $invoice->owner = $salesproject->owner;
        $invoice->sum = $sum;
        $invoice->sent = $time;
        $invoice->due = ($invoice->get_default('due') * 3600 * 24) + $time;
        $invoice->vat = $invoice->get_default('vat');
        $invoice->description = $deliverable->title . ' (' . $calculation_base . ')';
        if ($this->_sales_url) {
            $invoice->description = '<a href="' . $this->_sales_url . 'deliverable/' . $deliverable->guid . '/">' . $invoice->description . '</a>';
        }
        $invoice->paid = $invoice->due;

        return $invoice;
    }

    private function _get_scheduled_invoices() : array
    {
        $invoices = [];
        $qb = midcom_services_at_entry_dba::new_query_builder();
        $qb->add_constraint('method', '=', 'new_subscription_cycle');
        $qb->add_constraint('component', '=', 'org.openpsa.sales');
        foreach ($qb->execute() as $at_entry) {
            try {
                $deliverable = org_openpsa_sales_salesproject_deliverable_dba::get_cached($at_entry->arguments['deliverable']);
                if (   $deliverable->continuous
                    || (   $deliverable->start < $this->_request_data['end']
                        && $deliverable->end > $this->_request_data['start'])) {
                    $invoices = array_merge($invoices, $this->_get_invoices_for_subscription($deliverable, $at_entry));
                }
            } catch (midcom_error) {
            }
        }
        $invoices = array_merge($invoices, $this->_get_deliverable_invoices());
        return array_filter($invoices, $this->_filter_by_date(...));
    }

    private function _get_deliverable_invoices() : array
    {
        $invoices = [];
        $qb = org_openpsa_sales_salesproject_deliverable_dba::new_query_builder();
        $states = [
            org_openpsa_sales_salesproject_deliverable_dba::STATE_DELIVERED,
            org_openpsa_sales_salesproject_deliverable_dba::STATE_STARTED,
            org_openpsa_sales_salesproject_deliverable_dba::STATE_ORDERED
        ];
        $qb->add_constraint('state', 'IN', $states);
        $qb->add_constraint('orgOpenpsaObtype', '=', org_openpsa_products_product_dba::DELIVERY_SINGLE);
        $qb->add_constraint('start', '<', $this->_request_data['end']);
        $qb->add_constraint('end', '>', $this->_request_data['start']);

        $client_class = midcom_baseclasses_components_configuration::get('org.openpsa.sales', 'config')->get('calculator');
        $client = new $client_class();
        $formatter = $this->_l10n->get_formatter();
        $calculation_base = $this->_i18n->get_string('estimated delivery', 'org.openpsa.sales') . ': ';
        foreach ($qb->execute() as $deliverable) {
            $client->run($deliverable);
            if ($client->get_price()) {
                $invoices[] = $this->get_invoice_for_deliverable($deliverable, $client->get_price(), $deliverable->end, $calculation_base . $formatter->date($deliverable->end));
            }
        }
        return $invoices;
    }

    private function _filter_by_date(org_openpsa_invoices_invoice_dba $inv) : bool
    {
        return $inv->{$this->_request_data['date_field']} <= $this->_request_data['end'];
    }

    private function _load_invoices(string $status) : array
    {
        if ($status == 'scheduled') {
            $siteconfig = org_openpsa_core_siteconfig::get_instance();
            $this->_sales_url = $siteconfig->get_node_full_url('org.openpsa.sales');
            return $this->_get_scheduled_invoices();
        }

        $qb = org_openpsa_invoices_invoice_dba::new_query_builder();

        if ($status != 'unsent') {
            $qb->add_constraint($this->_request_data['date_field'], '>=', $this->_request_data['start']);
            $qb->add_constraint($this->_request_data['date_field'], '<', $this->_request_data['end']);
        }
        if ($this->_request_data['query_data']['resource'] != 'all') {
            $this->_request_data['query_data']['resource_expanded'] = $this->_expand_resource($this->_request_data['query_data']['resource']);
            $qb->add_constraint('owner', 'IN', $this->_request_data['query_data']['resource_expanded']);
        }

        switch ($status) {
            case 'unsent':
                $qb->add_constraint('sent', '=', 0);
                $qb->add_constraint('paid', '=', 0);
                break;
            case 'paid':
                $qb->add_constraint('paid', '>', 0);
                break;
            case 'overdue':
                $qb->add_constraint('sent', '>', 0);
                $qb->add_constraint('due', '<', time());
                $qb->add_constraint('paid', '=', 0);
                break;
            case 'open':
                $qb->add_constraint('sent', '>', 0);
                $qb->add_constraint('paid', '=', 0);
                $qb->add_constraint('due', '>=', time());
                break;
        }

        return $qb->execute();
    }

    public function _show_generator(string $handler_id, array &$data)
    {
        midcom_show_style('invoices_report-start');

        $siteconfig = org_openpsa_core_siteconfig::get_instance();
        $data['invoices_url'] = $siteconfig->get_node_full_url('org.openpsa.invoices');

        midcom_show_style('invoices_report-grid');

        midcom_show_style('invoices_report-end');
    }
}
