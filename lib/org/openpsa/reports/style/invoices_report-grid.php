<?php
$l10n = midcom::get()->i18n->get_l10n('org.openpsa.invoices');
$formatter = $l10n->get_formatter();

$status_options = [
    'scheduled' => $l10n->get('scheduled'),
    'canceled' => $l10n->get('canceled'),
    'unsent' => $l10n->get('unsent'),
    'paid' => $l10n->get('paid'),
    'overdue' => $l10n->get('overdue'),
    'open' => $l10n->get('open')
];
$entries = [];

$grid_id = 'invoices_report_grid';
if (!empty($data['query']) && $data['query']->orgOpenpsaObtype !== org_openpsa_reports_query_dba::OBTYPE_REPORT_TEMPORARY) {
    $grid_id .= $data['query']->id;
}

$footer_data = [
    'number' => $data['l10n']->get('totals'),
    'sum' => 0,
    'vat_sum' => 0
];
$sortname = 'date';
$sortorder = 'asc';
$cancelations = [];

foreach ($data['invoices'] as $invoice) {
    $entry = [
        'id' => $invoice->id,
        'index_number' => $invoice->number,
        'number' => $invoice->description,
        'date' => '',
        'year' => '',
        'month' => '',
        'index_month' => '',
        'customer' => '',
        'index_customer' => '',
        'status' => $invoice->get_status(),
        'index_contact' => '',
        'contact' => '',
        'sum' => $invoice->sum,
        'vat' => $invoice->vat,
        'vat_sum' => ($invoice->sum / 100) * $invoice->vat
    ];

    $footer_data['sum'] += $invoice->sum;
    $footer_data['vat_sum'] += $entry['vat_sum'];

    if ($invoice->id) {
        if ($data['invoices_url']) {
            $entry['number'] = "<a target='_blank' href=\"{$data['invoices_url']}invoice/{$invoice->guid}/\">" . $invoice->get_label() . "</a>";
        } else {
            $entry['number'] = $invoice->get_label();
        }
    }

    if ($invoice->{$data['date_field']} > 0) {
        $entry['date'] = date('Y-m-d', $invoice->{$data['date_field']});
        $entry['year'] = date('Y', $invoice->{$data['date_field']});
        $entry['month'] = $formatter->customdate($invoice->{$data['date_field']}, 'MMMM y');
        $entry['index_month'] = date('Ym', $invoice->{$data['date_field']});
    }
    try {
        $customer = org_openpsa_contacts_group_dba::get_cached($invoice->customer);
        $entry['index_customer'] = $customer->official;
        if ($data['invoices_url']) {
            $entry['customer'] = "<a href=\"{$data['invoices_url']}list/customer/all/{$customer->guid}/\" title=\"{$customer->name}: {$customer->official}\">{$customer->official}</a>";
        } else {
            $entry['customer'] = $customer->official;
        }
    } catch (midcom_error $e) {
    }

    if ($entry['status'] === 'canceled') {
        $cancelations[] = $invoice->cancelationInvoice;
    }

    try {
        $contact = org_openpsa_contacts_person_dba::get_cached($invoice->customerContact);
        $entry['index_contact'] = $contact->rname;
        $contact_card = org_openpsa_widgets_contact::get($invoice->customerContact);
        $entry['contact'] = $contact_card->show_inline();
    } catch (midcom_error $e) {
    }

    $entries[] = $entry;
}

if (!empty($cancelations)) {
    foreach ($entries as &$entry) {
        if (in_array($entry['id'], $cancelations)) {
            $entry['status'] = 'canceled';
            $entry['number'] .= ' (' . $l10n->get('cancelation invoice') . ')';
        }
    }
}

if ($data['date_field'] == 'date') {
    $data['date_field'] = 'invoice date';
}

$grid = new midcom\grid\grid($grid_id, 'local');

$grid->set_column('number', $l10n->get('invoice number'), 'width: 120', 'string')
    ->set_select_column('status', $data['l10n']->get('invoice status'), '', $status_options)
    ->set_column('date', $l10n->get($data['date_field']), 'width: 80, fixed: true, formatter: "date", align: "right"')
    ->set_column('month', '', 'hidden: true', 'number')
    ->set_column('year', '', 'hidden: true')
    ->set_column('customer', $l10n->get('customer'), 'width: 100', 'string')
    ->set_column('contact', $l10n->get('customer contact'), 'width: 100', 'string')
    ->set_column('sum', $l10n->get('sum excluding vat'), 'width: 90, fixed: true, template: "number", summaryType:"sum"')
    ->set_column('vat', $l10n->get('vat'), 'width: 40, fixed: true, align: "right", formatter: "currency", formatoptions: {suffix: " %", decimalPlaces: 0}')
    ->set_column('vat_sum', $l10n->get('vat sum'), 'width: 70, fixed: true, template: "number", summaryType:"sum"');

$grid->set_option('loadonce', true)
    ->set_option('grouping', true)
    ->set_option('groupingView', [
             'groupField' => ['status'],
             'groupColumnShow' => [false],
             'groupText' => ['<strong>{0}</strong> ({1})'],
             'groupOrder' => ['asc'],
             'groupSummary' => [true],
             'showSummaryOnHide' => true
         ])
    ->set_option('sortname', $sortname)
    ->set_option('sortorder', $sortorder);

$grid->set_footer_data($footer_data);

$filename = preg_replace('/[^a-z0-9-]/i', '_', $data['title'] . '_' . date('Y_m_d'));
?>
<canvas id="chart-&(grid_id);"></canvas>
<div class="grid-controls">
<?php
echo ' ' . midcom::get()->i18n->get_string('group by', 'org.openpsa.core') . ': ';
echo '<select id="chgrouping_' . $grid_id . '">';
echo '<option value="status">' . $data['l10n']->get('invoice status') . "</option>\n";
echo '<option value="customer">' . $l10n->get('customer') . "</option>\n";
echo '<option value="contact">' . $l10n->get('customer contact') . "</option>\n";
echo '<option value="year" data-hidden="true">' . $data['l10n']->get('year') . "</option>\n";
echo '<option value="month" data-hidden="true">' . $data['l10n']->get('month') . "</option>\n";
echo '<option value="clear">' . midcom::get()->i18n->get_string('no grouping', 'org.openpsa.core') . "</option>\n";
echo '</select>';
?>

<button id="&(grid_id);_export">
   <i class="fa fa-download"></i>
   <?php echo midcom::get()->i18n->get_string('download as CSV', 'org.openpsa.core'); ?>
</button>

</div>

<div class="report org_openpsa_invoices full-width fill-height">
    <?php $grid->render($entries); ?>
</div>

<script type="text/javascript">

midcom_grid_csv.add({
      id: '&(grid_id);',
      filename: '&(filename);',
      fields: {
          number: '<?php echo $l10n->get('invoice number'); ?>',
          status: '<?php echo $l10n->get('status'); ?>',
          date: '<?php echo $data['l10n_midcom']->get('date'); ?>',
          index_customer: '<?php echo $l10n->get('customer'); ?>',
          index_contact: '<?php echo $l10n->get('customer contact'); ?>',
          sum: '<?php echo $l10n->get('sum excluding vat'); ?>',
          vat: '<?php echo $l10n->get('vat'); ?>',
          vat_sum: '<?php echo $l10n->get('vat sum'); ?>'
        }
});
midcom_grid_helper.bind_grouping_switch('&(grid_id);');
init_chart('&(grid_id);');
</script>
