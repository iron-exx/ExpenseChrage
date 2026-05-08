<?php
/**
 * TCPDF Template für Wallbox-Abrechnung
 *
 * @category   Billing
 * @package    WallboxBilling
 */
require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

/**
 * ModelePDFFactures pour Wallbox Billing
 */
class PdfWallboxBilling extends ModelePDFFactures
{
    /**
     * Database pointer
     * @var DoliDB
     */
    public $db;

    /**
     * Nom du modele de PDF
     * @var string
     */
    public $name;

    /**
     * Description du modele de PDF
     * @var string
     */
    public $description;

    /**
     * Update main doc field
     * @var int
     */
    public $update_main_doc_file = 1;

    /**
     * Format portrait
     * @var string
     */
    public $format = 'PORTRAIT';

    /**
     * Position X description
     * @var float
     */
    public $posxdesc = 30;

    /**
     * Position X date
     * @var float
     */
    public $posxdate = 30;

    /**
     * Position X quantity
     * @var float
     */
    public $posxqty = 100;

    /**
     * Position X unit price
     * @var float
     */
    public $posxup = 130;

    /**
     * Position X total
     * @var float
     */
    public $posxright = 170;

    /**
     * Page width
     * @var float
     */
    public $width = 210;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs;
        $this->db = $db;
        $this->name = $langs->trans('WallboxBilling');
        $this->description = $langs->trans('PDFTemplateWallboxBilling');
    }

    /**
     * Write PDF file
     *
     * @param Object $object Object to process
     * @param Translate $outputlangs Output language
     * @param string $srctemplatepath Template path
     * @param int $hidedetails Hide details
     * @param int $hidedesc Hide description
     * @param int $hideref Hide reference
     * @return int 0=OK, >0=KO
     */
    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $conf, $langs, $mysoc;

        $this->db = $object->db;

        $default_font_size = pdf_getDefaultFontSize();

        $pdf = pdf_getInstance();

        if (empty($this->define_header_pdf)) {
            $this->define_header_pdf($pdf, $object, $outputlangs);
        }

        $pdf->SetAutoPageBreak(1, 25);

        $pdf->SetFont(pdf_getPDFFont($outputlangs));
        $pdf->SetTextColor(0, 0, 0);

        $page = 1;

        $pdf->Open();
        $pdf->AddPage();

        $this->_pagehead($pdf, $object, 1, $outputlangs);
        $pdf->SetFont(pdf_getPDFFont($outputlangs), '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $this->_tableau($pdf, $object, $outputlangs);

        $this->_tableau_tot($pdf, $object, $outputlangs);

        $this->_pagefoot($pdf, $object, $outputlangs);

        $outputdir = $conf->wallboxbilling->dir_output;
        if (!is_dir($outputdir)) {
            dol_mkdir($outputdir);
        }

        $filename = dol_sanitizeFileName('wallbox_billing_' . $object->billing_year . '_' . sprintf('%02d', $object->billing_month) . '_' . $object->fk_user . '.pdf');
        $fullpath = $outputdir . '/' . $filename;

        $pdf->Output($fullpath, 'F');

        if (!empty($conf->global->MAIN_UMASK)) {
            @chmod($fullpath, octdec($conf->global->MAIN_UMASK));
        }

        $this->result = array('fullpath' => $fullpath);

        return 1;
    }

    /**
     * Show header of page
     *
     * @param TCPDF $pdf PDF object
     * @param Object $object Object to show
     * @param int $showaddress 0=no, 1=yes
     * @param Translate $outputlangs Output language
     * @return void
     */
    protected function _pagehead($pdf, $object, $showaddress, $outputlangs)
    {
        global $conf, $langs, $mysoc;

        $default_font_size = pdf_getDefaultFontSize();

        $pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 12);
        $pdf->SetXY(10, 8);
        $pdf->MultiCell(100, 3, $outputlangs->transnoentities('WallboxAbrechnung'), 0, 'L');

        $pdf->SetFont(pdf_getPDFFont($outputlangs), '', 10);
        $pdf->SetXY(10, 15);
        $pdf->MultiCell(100, 3, $mysoc->name, 0, 'L');

        if (!empty($mysoc->address)) {
            $pdf->SetXY(10, 20);
            $pdf->MultiCell(100, 3, $mysoc->address, 0, 'L');
        }

        if (!empty($mysoc->zip) || !empty($mysoc->town)) {
            $pdf->SetXY(10, 25);
            $pdf->MultiCell(100, 3, $mysoc->zip . ' ' . $mysoc->town, 0, 'L');
        }

        $pdf->SetXY(150, 8);
        $pdf->SetFont(pdf_getPDFFont($outputlangs), '', 8);
        $pdf->MultiCell(50, 3, $outputlangs->transnoentities('BillingDate') . ': ' . dol_print_date(dol_now(), 'day', false, $outputlangs), 0, 'R');

        $monthNames = array(
            1 => $outputlangs->transnoentities('January'),
            2 => $outputlangs->transnoentities('February'),
            3 => $outputlangs->transnoentities('March'),
            4 => $outputlangs->transnoentities('April'),
            5 => $outputlangs->transnoentities('May'),
            6 => $outputlangs->transnoentities('June'),
            7 => $outputlangs->transnoentities('July'),
            8 => $outputlangs->transnoentities('August'),
            9 => $outputlangs->transnoentities('September'),
            10 => $outputlangs->transnoentities('October'),
            11 => $outputlangs->transnoentities('November'),
            12 => $outputlangs->transnoentities('December')
        );

        $period = $monthNames[$object->billing_month] . ' ' . $object->billing_year;

        $pdf->SetXY(150, 13);
        $pdf->MultiCell(50, 3, $outputlangs->transnoentities('BillingPeriod') . ': ' . $period, 0, 'R');

        $pdf->SetXY(150, 18);
        $pdf->MultiCell(50, 3, $outputlangs->transnoentities('User') . ': ' . $object->user_name, 0, 'R');

        $pdf->SetLineWidth(0.5);
        $pdf->Line(10, 35, 200, 35);
    }

    /**
     * Show table with sessions
     *
     * @param TCPDF $pdf PDF object
     * @param Object $object Object
     * @param Translate $outputlangs Output language
     * @return void
     */
    protected function _tableau($pdf, $object, $outputlangs)
    {
        global $conf;

        $pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 9);
        $pdf->SetXY(10, 40);
        $pdf->MultiCell(180, 8, $outputlangs->transnoentities('Ladeliste'), 0, 'L');

        $pdf->SetFont(pdf_getPDFFont($outputlangs), '', 8);

        $col1 = 10;
        $col2 = 40;
        $col3 = 70;
        $col4 = 100;
        $col5 = 125;
        $col6 = 150;
        $col7 = 170;

        $pdf->SetXY($col1, 50);
        $pdf->MultiCell($col2 - $col1, 6, $outputlangs->transnoentities('Datum'), 1, 'C');

        $pdf->SetXY($col2, 50);
        $pdf->MultiCell($col3 - $col2, 6, $outputlangs->transnoentities('Wallbox'), 1, 'C');

        $pdf->SetXY($col3, 50);
        $pdf->MultiCell($col4 - $col3, 6, $outputlangs->transnoentities('Start'), 1, 'C');

        $pdf->SetXY($col4, 50);
        $pdf->MultiCell($col5 - $col4, 6, $outputlangs->transnoentities('Ende'), 1, 'C');

        $pdf->SetXY($col5, 50);
        $pdf->MultiCell($col6 - $col5, 6, $outputlangs->transnoentities('kWh'), 1, 'C');

        $pdf->SetXY($col6, 50);
        $pdf->MultiCell($col7 - $col6, 6, $outputlangs->transnoentities('PreiskWh'), 1, 'C');

        $pdf->SetXY($col7, 50);
        $pdf->MultiCell(30, 6, $outputlangs->transnoentities('Gesamt'), 1, 'C');

        $posy = 56;
        $line_height = 6;

        if (!empty($object->sessions) && is_array($object->sessions)) {
            foreach ($object->sessions as $session) {
                $pdf->SetXY($col1, $posy);
                $pdf->MultiCell($col2 - $col1, $line_height, dol_print_date($session['start_time'], 'day', false, $outputlangs), 0, 'L');

                $pdf->SetXY($col2, $posy);
                $pdf->MultiCell($col3 - $col2, $line_height, $session['wallbox_id'], 0, 'L');

                $pdf->SetXY($col3, $posy);
                $pdf->MultiCell($col4 - $col3, $line_height, dol_print_date($session['start_time'], 'hour', false, $outputlangs), 0, 'L');

                $pdf->SetXY($col4, $posy);
                $end_time = !empty($session['end_time']) ? dol_print_date($session['end_time'], 'hour', false, $outputlangs) : '-';
                $pdf->MultiCell($col5 - $col4, $line_height, $end_time, 0, 'L');

                $pdf->SetXY($col5, $posy);
                $pdf->MultiCell($col6 - $col5, $line_height, number_format($session['kwh'], 2, ',', ''), 0, 'R');

                $pdf->SetXY($col6, $posy);
                $pdf->MultiCell($col7 - $col6, $line_height, number_format($session['price_per_kwh'], 2, ',', '') . ' €', 0, 'R');

                $total = $session['kwh'] * $session['price_per_kwh'];
                $pdf->SetXY($col7, $posy);
                $pdf->MultiCell(30, $line_height, number_format($total, 2, ',', '') . ' €', 0, 'R');

                $posy += $line_height;

                if ($posy > 270) {
                    $pdf->AddPage();
                    $posy = 20;
                }
            }
        }
    }

    /**
     * Show summary table
     *
     * @param TCPDF $pdf PDF object
     * @param Object $object Object
     * @param Translate $outputlangs Output language
     * @return void
     */
    protected function _tableau_tot($pdf, $object, $outputlangs)
    {
        global $conf;

        $posy = 150;

        if (!empty($object->sessions) && is_array($object->sessions)) {
            $posy = 50 + (count($object->sessions) * 6) + 10;
        }

        $pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 10);
        $pdf->SetXY(10, $posy);
        $pdf->MultiCell(180, 8, $outputlangs->transnoentities('Zusammenfassung'), 0, 'L');

        $pdf->SetFont(pdf_getPDFFont($outputlangs), '', 9);
        $posy += 10;

        $pdf->SetXY(10, $posy);
        $pdf->MultiCell(120, 6, $outputlangs->transnoentities('GesamtWh'), 0, 'L');
        $pdf->SetXY(130, $posy);
        $pdf->MultiCell(30, 6, number_format($object->total_kwh, 2, ',', '') . ' kWh', 0, 'R');

        $posy += 8;
        $pdf->SetXY(10, $posy);
        $pdf->MultiCell(120, 6, $outputlangs->transnoentities('GesamtKosten'), 0, 'L');
        $pdf->SetXY(130, $posy);
        $pdf->MultiCell(30, 6, number_format($object->total_cost, 2, ',', '') . ' €', 0, 'R');

        $posy += 8;
        $pdf->SetXY(10, $posy);
        $pdf->MultiCell(120, 6, $outputlangs->transnoentities('AnzahlLadevorgaenge'), 0, 'L');
        $pdf->SetXY(130, $posy);
        $pdf->MultiCell(30, 6, $object->session_count, 0, 'R');

        $posy += 15;
        $pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 11);
        $pdf->SetXY(10, $posy);
        $pdf->MultiCell(120, 8, $outputlangs->transnoentities('Rechnungsbetrag'), 0, 'L');
        $pdf->SetXY(130, $posy);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->MultiCell(30, 8, number_format($object->total_cost, 2, ',', '') . ' €', 1, 'R');
    }

    /**
     * Show footer of page
     *
     * @param TCPDF $pdf PDF object
     * @param Object $object Object
     * @param Translate $outputlangs Output language
     * @return void
     */
    protected function _pagefoot($pdf, $object, $outputlangs)
    {
        global $conf;

        $pdf->SetFont(pdf_getPDFFont($outputlangs), '', 8);
        $pdf->SetXY(10, 280);
        $pdf->MultiCell(190, 3, $outputlangs->transnoentities('WallboxBillingFooter'), 0, 'C');

        $pdf->SetFont(pdf_getPDFFont($outputlangs), 'I', 8);
        $pdf->SetXY(10, 285);
        $pdf->MultiCell(190, 3, $outputlangs->transnoentities('Page') . ' ' . $pdf->PageNo(), 0, 'R');
    }

    /**
     * Define header PDF
     *
     * @param TCPDF $pdf PDF object
     * @param Object $object Object
     * @param Translate $outputlangs Output language
     * @return void
     */
    public function define_header_pdf($pdf, $object, $outputlangs)
    {
        // Can be overridden in child class
    }
}