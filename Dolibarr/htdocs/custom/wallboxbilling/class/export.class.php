<?php
/**
 * Export-Klasse für Wallbox-Abrechnungen
 * CSV und DATEV Export
 *
 * @category   Billing
 * @package    WallboxBilling
 */

/**
 * WallboxExport - Export-Klasse für CSV und DATEV
 */
class WallboxExport
{
    /**
     * Database handler
     * @var DoliDB
     */
    public $db;

    /**
     * Error message
     * @var string
     */
    public $error;

    /**
     * Error code
     * @var int
     */
    public $errno;

    /**
     * DATEV configuration
     * @var array
     */
    protected $datevConfig = array(
        'berater_nr' => '',
        'mandanten_nr' => '001',
        'buchungskreis' => '00',
        'kontenplan' => 'SKR03'
    );

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Generate CSV export
     *
     * @param array $billings Array of billing objects
     * @param string $outputPath Output file path
     * @return bool True on success, false on failure
     */
    public function generateCsv($billings, $outputPath)
    {
        global $conf, $langs;

        if (empty($billings) || !is_array($billings)) {
            $this->error = $langs->trans('ExportNoData');
            return false;
        }

        $outputdir = dirname($outputPath);
        if (!is_dir($outputdir)) {
            if (!dol_mkdir($outputdir)) {
                $this->error = $langs->trans('ExportCannotCreateDir');
                return false;
            }
        }

        $fp = fopen($outputPath, 'w');
        if (!$fp) {
            $this->error = $langs->trans('ExportCannotOpenFile');
            return false;
        }

        if (!empty($conf->global->MAIN_FORCE_UTF8)) {
            fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
        }

        $header = array(
            $langs->transnoentities('UserID'),
            $langs->transnoentities('UserLogin'),
            $langs->transnoentities('Name'),
            $langs->transnoentities('BillingMonth'),
            $langs->transnoentities('BillingYear'),
            $langs->transnoentities('TotalKWh'),
            $langs->transnoentities('PricePerKWh'),
            $langs->transnoentities('TotalCost'),
            $langs->transnoentities('SessionCount')
        );
        fputcsv($fp, $header, ';');

        foreach ($billings as $billing) {
            $row = array(
                $this->escape($billing->fk_user),
                $this->escape($billing->user_login),
                $this->escape($billing->user_name),
                $this->formatAmount($billing->billing_month, 0),
                $this->formatAmount($billing->billing_year, 0),
                $this->formatAmount($billing->total_kwh, 2),
                $this->formatAmount($billing->price_per_kwh, 2),
                $this->formatAmount($billing->total_cost, 2),
                $this->formatAmount($billing->session_count, 0)
            );
            fputcsv($fp, $row, ';');
        }

        fclose($fp);

        if (!empty($conf->global->MAIN_UMASK)) {
            @chmod($outputPath, octdec($conf->global->MAIN_UMASK));
        }

        return true;
    }

    /**
     * Generate DATEV EXTF format export
     *
     * @param array $billings Array of billing objects
     * @param string $outputPath Output file path
     * @param array $config DATEV configuration
     * @return bool True on success, false on failure
     */
    public function generateDatev($billings, $outputPath, $config = array())
    {
        global $conf, $langs;

        if (empty($billings) || !is_array($billings)) {
            $this->error = $langs->trans('ExportNoData');
            return false;
        }

        if (!empty($config)) {
            $this->datevConfig = array_merge($this->datevConfig, $config);
        }

        $outputdir = dirname($outputPath);
        if (!is_dir($outputdir)) {
            if (!dol_mkdir($outputdir)) {
                $this->error = $langs->trans('ExportCannotCreateDir');
                return false;
            }
        }

        $fp = fopen($outputPath, 'w');
        if (!$fp) {
            $this->error = $langs->trans('ExportCannotOpenFile');
            return false;
        }

        fprintf($fp, "EXTF;%s;5\n", date('Ymd'));

        $headerFields = array(
            'Kennung',
            'Vers',
            'Konto',
            'Gegenkonto (ohne)',
            'Belegdatum',
            'Buchungstext',
            'Soll/Haben',
            'Betrag',
            'KOST1',
            'KOST2'
        );
        fprintf($fp, "%s\n", implode(';', $headerFields));

        foreach ($billings as $billing) {
            $belegdatum = sprintf('%02d.%02d.%04d', 1, $billing->billing_month, $billing->billing_year);
            $buchungstext = sprintf('Wallbox-Abrechnung %02d/%04d',
                $billing->billing_month,
                $billing->billing_year
            );

            $debitorenkonto = $this->getDatevAccount($billing->fk_user);
            $umsatzkonto = '1400';
            $betragCent = $this->formatAmount($billing->total_cost * 100, 0);

            fprintf($fp, "%s;%s;%s;%s;%s;%s;S;%s;%s;%s\n",
                $this->datevConfig['berater_nr'],
                $this->datevConfig['vers'],
                $debitorenkonto,
                $umsatzkonto,
                $belegdatum,
                $this->escape($buchungstext),
                $betragCent,
                $billing->fk_user,
                ''
            );

            fprintf($fp, "%s;%s;%s;%s;%s;%s;H;%s;%s;%s\n",
                $this->datevConfig['berater_nr'],
                $this->datevConfig['vers'],
                $umsatzkonto,
                $debitorenkonto,
                $belegdatum,
                $this->escape($buchungstext),
                $betragCent,
                $billing->fk_user,
                ''
            );
        }

        fclose($fp);

        if (!empty($conf->global->MAIN_UMASK)) {
            @chmod($outputPath, octdec($conf->global->MAIN_UMASK));
        }

        return true;
    }

    /**
     * Generate DATEV export for accounting entries
     *
     * @param array $billings Array of billing objects
     * @param string $outputPath Output file path
     * @param array $config DATEV configuration
     * @return bool True on success, false on failure
     */
    public function generateDatevAccounting($billings, $outputPath, $config = array())
    {
        global $conf, $langs;

        if (empty($billings) || !is_array($billings)) {
            $this->error = $langs->trans('ExportNoData');
            return false;
        }

        if (!empty($config)) {
            $this->datevConfig = array_merge($this->datevConfig, $config);
        }

        $outputdir = dirname($outputPath);
        if (!is_dir($outputdir)) {
            if (!dol_mkdir($outputdir)) {
                $this->error = $langs->trans('ExportCannotCreateDir');
                return false;
            }
        }

        $fp = fopen($outputPath, 'w');
        if (!$fp) {
            $this->error = $langs->trans('ExportCannotOpenFile');
            return false;
        }

        fprintf($fp, "EXTF;%s;5\n", date('Ymd'));

        $headerFields = array(
            'ID',
            'Konto',
            'Gegenkonto',
            'Belegdatum',
            'Buchungsdatum',
            'Buchungstext',
            'Soll',
            'Haben',
            'WKZ'
        );
        fprintf($fp, "%s\n", implode(';', $headerFields));

        $lineId = 1;
        foreach ($billings as $billing) {
            $belegdatum = sprintf('%02d.%02d.%04d', 1, $billing->billing_month, $billing->billing_year);
            $buchungstext = sprintf('Wallbox-Abrechnung %02d/%04d - %s',
                $billing->billing_month,
                $billing->billing_year,
                $billing->user_name
            );

            $debitorenkonto = $this->getDatevAccount($billing->fk_user);
            $umsatzkonto = '1400';

            fprintf($fp, "%d;%s;%s;%s;%s;%s;%.2f;0;EUR\n",
                $lineId++,
                $debitorenkonto,
                $umsatzkonto,
                $belegdatum,
                $belegdatum,
                $this->escape($buchungstext),
                $billing->total_cost
            );

            fprintf($fp, "%d;%s;%s;%s;%s;%s;0;%.2f;EUR\n",
                $lineId++,
                $umsatzkonto,
                $debitorenkonto,
                $belegdatum,
                $belegdatum,
                $this->escape($buchungstext),
                $billing->total_cost
            );
        }

        fclose($fp);

        if (!empty($conf->global->MAIN_UMASK)) {
            @chmod($outputPath, octdec($conf->global->MAIN_UMASK));
        }

        return true;
    }

    /**
     * Export sessions to CSV
     *
     * @param array $sessions Array of session objects
     * @param string $outputPath Output file path
     * @return bool True on success, false on failure
     */
    public function exportSessionsCsv($sessions, $outputPath)
    {
        global $conf, $langs;

        if (empty($sessions) || !is_array($sessions)) {
            $this->error = $langs->trans('ExportNoData');
            return false;
        }

        $outputdir = dirname($outputPath);
        if (!is_dir($outputdir)) {
            if (!dol_mkdir($outputdir)) {
                $this->error = $langs->trans('ExportCannotCreateDir');
                return false;
            }
        }

        $fp = fopen($outputPath, 'w');
        if (!$fp) {
            $this->error = $langs->trans('ExportCannotOpenFile');
            return false;
        }

        if (!empty($conf->global->MAIN_FORCE_UTF8)) {
            fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
        }

        $header = array(
            $langs->transnoentities('SessionID'),
            $langs->transnoentities('UserID'),
            $langs->transnoentities('RFIDHash'),
            $langs->transnoentities('WallboxID'),
            $langs->transnoentities('StartTime'),
            $langs->transnoentities('EndTime'),
            $langs->transnoentities('KWh'),
            $langs->transnoentities('PricePerKWh'),
            $langs->transnoentities('TotalCost'),
            $langs->transnoentities('Status')
        );
        fputcsv($fp, $header, ';');

        foreach ($sessions as $session) {
            $row = array(
                $session->id,
                $session->fk_user,
                $session->rfid_hash,
                $session->wallbox_id,
                $this->formatDate($session->start_time),
                $this->formatDate($session->end_time),
                $this->formatAmount($session->kwh, 2),
                $this->formatAmount($session->price_per_kwh, 2),
                $this->formatAmount($session->total_cost, 2),
                $this->escape($session->status)
            );
            fputcsv($fp, $row, ';');
        }

        fclose($fp);

        if (!empty($conf->global->MAIN_UMASK)) {
            @chmod($outputPath, octdec($conf->global->MAIN_UMASK));
        }

        return true;
    }

    /**
     * Format amount for export
     *
     * @param float $amount Amount to format
     * @param int $decimals Number of decimals
     * @return string Formatted amount
     */
    public function formatAmount($amount, $decimals = 2)
    {
        return number_format($amount, $decimals, ',', '');
    }

    /**
     * Format date for export
     *
     * @param string|int $timestamp Unix timestamp or date string
     * @return string Formatted date
     */
    public function formatDate($timestamp)
    {
        if (empty($timestamp)) {
            return '';
        }

        if (is_numeric($timestamp)) {
            return dol_print_date($timestamp, 'day', false);
        }

        return dol_print_date(strtotime($timestamp), 'day', false);
    }

    /**
     * Escape string for CSV/DATEV
     *
     * @param string $str String to escape
     * @return string Escaped string
     */
    public function escape($str)
    {
        if (empty($str)) {
            return '';
        }

        $str = str_replace(array("\r\n", "\r", "\n"), ' ', $str);
        $str = str_replace(';', ',', $str);
        $str = str_replace('"', "'", $str);

        return $str;
    }

    /**
     * Get DATEV account number for user
     *
     * @param int $userId User ID
     * @return string DATEV account number
     */
    public function getDatevAccount($userId)
    {
        return sprintf('1%05d', $userId);
    }

    /**
     * Set DATEV configuration
     *
     * @param array $config Configuration array
     * @return void
     */
    public function setDatevConfig($config)
    {
        $this->datevConfig = array_merge($this->datevConfig, $config);
    }

    /**
     * Get DATEV configuration
     *
     * @return array DATEV configuration
     */
    public function getDatevConfig()
    {
        return $this->datevConfig;
    }

    /**
     * Get last error message
     *
     * @return string Error message
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Get last error number
     *
     * @return int Error number
     */
    public function getErrno()
    {
        return $this->errno;
    }
}