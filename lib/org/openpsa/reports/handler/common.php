<?php
/**
 * @package org.openpsa.reports
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Common handlers
 *
 * @package org.openpsa.reports
 */
class org_openpsa_reports_handler_common extends midcom_baseclasses_components_handler
{
    public function _handler_frontpage(array &$data)
    {
        midcom::get()->auth->require_valid_user();
        $data['available_components'] = org_openpsa_reports_viewer::get_available_generators();
        $this->add_stylesheet(MIDCOM_STATIC_URL . '/' . $this->_component . '/common.css');

        return $this->show('show-frontpage');
    }

    /**
     * Delete the given report and redirect to front page
     */
    public function _handler_delete_report(array $args)
    {
        $report = new org_openpsa_reports_query_dba($args[0]);
        $report->delete();
        return new midcom_response_relocate('');
    }

    /**
     * The CSV handlers return a posted variable with correct headers
     */
    public function _handler_csv(Request $request)
    {
        if (!$request->request->has('org_openpsa_reports_csv')) {
            throw new midcom_error('Variable org_openpsa_reports_csv not set in _POST');
        }

        //We're outputting CSV
        return new Response($request->request->get('org_openpsa_reports_csv'), Response::HTTP_OK, [
            'Content-Type' => 'application/csv'
        ]);
    }
}
