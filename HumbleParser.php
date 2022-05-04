<?php

/**
 * Requires:
 *  - php cli >= 7.4
 *  - php-curl
 */

use Safe\Exceptions\CurlException;

final class HumbleParser
{
    // humble switched from "Humble Monthly" to "Humble Choice on Dec. 2019"
    // On Dec. 2019, there were both, a "Monthly" and a "Choice"
    public const CHOICE_START_YEAR = 2019;
    public const CHOICE_START_MONTH = 12;
    public const URL_HOME = 'https://www.humblebundle.com';

    public string $cookie = '';
    public int $year_start = 2018;
    public int $month_start = 7;
    public bool $use_cache = true;
    public string $cache_dir = '';
    public string $output_dir = '';

    // internal
    private array $game_data = [];

    /**
     * @throws CurlException
     */
    public function parseMonthly(int $month, int $year): array
    {
        // fetch overview page
        $time = mktime(0, 0, 0, $month, 1, $year);
        if ($time === false) {
            $this->log("[parseMonthly][$month/$year] failed create timestamp");

            return [];
        }

        $pageOverview = $this->fetchURL(
            '/monthly/p/' . strtolower(strftime('%B', $time)) . '_' . $year . '_monthly'
        );
        if (!$pageOverview) {
            $this->log("[parseMonthly][$month/$year] failed to fetch overview page");

            return [];
        }

        // get key for overview page
        if (preg_match("/href=\"\/downloads\?key=([a-zA-Z\d]+)\"/isU", $pageOverview, $match) === false) {
            $this->log("[parseMonthly][$month/$year] failed to parse key from overview page");

            return [];
        }

        // fetch bundle detail page
        $pageDetail = $this->fetchURL(
            '/api/v1/order/' . $match[1] . '?wallet_data=true&all_tpkds=true&get_coupons=true'
        );
        if (!$pageDetail) {
            $this->log("[parseMonthly][$month/$year] failed to fetch detail page");

            return [];
        }

        $gameData = json_decode($pageDetail, true);
        if (!is_array($gameData)) {
            $this->log("[parseMonthly][$month/$year] could not parse detail page json");

            return [];
        }

        if (!isset($gameData['tpkd_dict']) || !isset($gameData['tpkd_dict']['all_tpks'])) {
            $this->log("[parseMonthly][$month/$year] failed to fetch game blocks from detail page");
        }

        $out = [];

        foreach ($gameData['tpkd_dict']['all_tpks'] as $row) {
            $out[] = [
                'name' => $row['human_name'],
                'expired' => $row['is_expired'],
                'expires' => $row['num_days_until_expired'],
                'store' => $row['key_type'],
                'key' => $row['redeemed_key_val'] ?? '',
                'steam_app_id' => $row['steam_app_id'] ?? '',
                'redeemed' => isset($row['redeemed_key_val']),
                'redeem_page' => self::URL_HOME . '/downloads?key=' . $match[1],
                'humble_type' => 'Monthly',
                'choices_remaining' => false
            ];
        }

        return $out;
    }

    public function parseChoice(int $month, int $year): array
    {
        // fetch overview page
        $time = mktime(0, 0, 0, $month, 1, $year);
        if ($time === false) {
            $this->log("[parseChoice][$month/$year] failed create timestamp");

            return [];
        }

        $pageOverview = $this->fetchURL(
            '/membership/' . strtolower(strftime('%B', $time)) . '-' . $year
        );
        if (!$pageOverview) {
            $this->log("[parseChoice][$month/$year] failed to fetch overview page");

            return [];
        }

        // fetch game blocks
        if (!preg_match("/<script id=\"webpack-monthly-product-data\" type=\"application\/json\">(.+)<\/script>/isU", $pageOverview, $match)) {
            $this->log("[parseChoice][$month/$year] failed to parse game blocks");
            return [];
        }

        $gameData = json_decode($match[1], true);
        if (!is_array($gameData)) {
            $this->log("[parseChoice][$month/$year] could not parse detail page json");
            return [];
        }

        // they change their json structure at least 2 times...
        $dataPointer = null;
        $gameData = $gameData['contentChoiceOptions']['contentChoiceData'];
        if (isset($gameData['initial-get-all-games'])) {
            $dataPointer = $gameData['initial-get-all-games']['content_choices'];
            $totalChoices = $gameData['initial-get-all-games']['total_choices'];
            $remainingChoices = $totalChoices - substr_count($match[1], 'redeemed_key_val');
        } elseif (isset($gameData['initial'])) {
            $dataPointer = $gameData['initial']['content_choices'];
            $totalChoices = $gameData['initial']['total_choices'];
            $remainingChoices = $totalChoices - substr_count($match[1], 'redeemed_key_val');
        } elseif (isset($gameData['game_data'])) {
            $dataPointer = $gameData['game_data'];
            $totalChoices = 'n/a';
            $remainingChoices = 'n/a';
        } else {
            print_r($gameData);
            die("no data pointer");
        }

        $out = [];

        foreach ($dataPointer as $key => $row) {
            if (isset($row['tpkds'])) {
                $row = $row['tpkds'][0];
            }

            // multiple stores.
            if (isset($row['nested_choice_tpkds'])) {
                $keys = array_keys($row['nested_choice_tpkds']);
                // get steam only
                $idx = array_search('_steam', $keys);
                $row = $row['nested_choice_tpkds'][$keys[$idx]][0];
            }

            $out[] = [
                'name' => $row['human_name'],
                'expired' => $row['is_expired'],
                'expires' => $row['num_days_until_expired'],
                'store' => $row['key_type'],
                'key' => $row['redeemed_key_val'] ?? '',
                'steam_app_id' => $row['steam_app_id'] ?? '',
                'redeemed' => isset($row['redeemed_key_val']),
                'redeem_page' => self::URL_HOME . '/membership/' . strtolower(strftime('%B', $time)) . '-' . $year,
                'humble_type' => 'Choice',
                'choices_remaining' => $remainingChoices
            ];
        }

        return $out;
    }

    public function run(): bool
    {
        if ($this->getCookie() == '') {
            $this->log('Cookie data not set');

            return false;
        }

        if (!$this->cache_dir && $this->use_cache) {
            $this->log('Cache requested but cache directory not set');

            return false;
        }

        if (!$this->getOutputDir()) {
            $this->log('Output directory not set');
            return false;
        }

        $thisYear = date('Y');
        $thisMonth = date('n');

        for ($year = $this->year_start; $year <= $thisYear; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                if ($year == $this->year_start && $month < $this->month_start) {
                    continue;
                }

                // if current year, abort future months
                if ($year == $thisYear && $month > $thisMonth) {
                    $this->log("Abort future: $year / $month");
                    break 2;
                }
                $this->log("$year / $month");

                // monthly or choice?
                $isMonthly = false;
                $isChoice = false;

                if ($year <= self::CHOICE_START_YEAR && $month <= self::CHOICE_START_MONTH) {
                    $isMonthly = true;
                }
                if ($year === self::CHOICE_START_YEAR && $month === self::CHOICE_START_MONTH) {
                    $isChoice = true;
                }
                if ($year > self::CHOICE_START_YEAR) {
                    $isChoice = true;
                }

                // prepare target array structure
                if (!isset($this->game_data[$year])) {
                    $this->game_data[$year] = [];
                }
                if (!isset($this->game_data[$year][$month])) {
                    $this->game_data[$year][$month] = [];
                }

                if ($isMonthly) {
                    $data = $this->parseMonthly($month, $year);
                    if ($data) {
                        $this->game_data[$year][$month] = array_merge($this->game_data[$year][$month], $data);
                    } else {
                        $this->log("Failed to parse monthly for $month/$year");
                    }
                }

                if ($isChoice) {
                    $data = $this->parseChoice($month, $year);
                    if ($data) {
                        $this->game_data[$year][$month] = array_merge($this->game_data[$year][$month], $data);
                    } else {
                        $this->log("Failed to parse choice for $month/$year");
                    }
                }
            }
        }

        if ($this->game_data) {
            $this->writeCSV($this->game_data);
        }

        $this->log('ALL DONE!');

        return true;
    }

    private function fetchURL(string $url): string
    {
        $cache_hash = md5($url);

        $url = self::URL_HOME . $url;

        if ($this->use_cache) {
            if (!is_dir($this->cache_dir)) {
                mkdir($this->cache_dir, 0777, true);
            }
            if (file_exists($this->cache_dir . '/' . $cache_hash)) {
                $this->log('return from cache (' . $cache_hash . '): ' . $url);

                return file_get_contents($this->cache_dir . '/' . $cache_hash);
            }
        }

        if (!($ch = curl_init($url))) {
            $this->log('[fetchURL] Failed to init CURL');

            return '';
        }

        $this->log("Calling $url");

        $cookie = $this->getCookie();

        #curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; .NET CLR 1.0.3705; .NET CLR 1.1.4322; Media Center PC 4.0)');
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);

        $result = curl_exec($ch);
        curl_close($ch);

        // always be nice!
        usleep(rand(100, 250) * 10000);

        if (is_string($result)) {
            if ($this->use_cache) {
                file_put_contents($this->cache_dir . '/' . $cache_hash, $result);
            }

            return $result;
        }

        $this->log('[fetchURL] empty result for query to ' . $url);

        return '';
    }

    /**
     * @return string
     */
    public function getCacheDir(): string
    {
        return $this->cache_dir;
    }

    /**
     * @param string $cache_dir
     */
    public function setCacheDir(string $cache_dir): void
    {
        $this->cache_dir = $cache_dir;
    }

    /**
     * @return bool
     */
    public function isUseCache(): bool
    {
        return $this->use_cache;
    }

    /**
     * @param bool $use_cache
     */
    public function setUseCache(bool $use_cache): void
    {
        $this->use_cache = $use_cache;
    }

    private function writeCSV(array $data): bool
    {

        if (!is_dir($this->getOutputDir())) {
            mkdir($this->getOutputDir(), 0777, true);
        }

        if (!($fp = fopen($this->getOutputDir() . '/humble_gamelist.csv', 'w'))) {
            die("failed to open output file");
        }

        // header
        fputcsv($fp, [
            'Year',
            'Month',
            'Game',
            'Store',
            'Redeemed',
            'Expired',
            'Choices left',
            'Redeem Page'
        ]);

        foreach ($data as $year => $year_data) {
            foreach ($year_data as $month => $games) {
                foreach ($games as $game) {
                    fputcsv($fp, [
                        $year,
                        strftime("%B", strtotime("15th December +" . $month . " months")), // sorry xD
                        $game['name'],
                        $game['store'],
                        $game['redeemed'] ? 'Yes' : 'No',
                        $game['expired'] ? 'Yes' : 'No',
                        $game['choices_remaining'] ?: 'n/a',
                        $game['redeem_page']
                    ]);
                }
            }
        }

        fclose($fp);
        return true;
    }

    private function log(string $str): void
    {
        echo $str . PHP_EOL;
    }

    /**
     * @return string
     */
    public function getCookie(): string
    {
        return $this->cookie;
    }

    /**
     * @param string $cookie
     */
    public function setCookie(string $cookie): void
    {
        $this->cookie = $cookie;
    }

    /**
     * @return string
     */
    public function getOutputDir(): string
    {
        return $this->output_dir;
    }

    /**
     * @param string $output_dir
     */
    public function setOutputDir(string $output_dir): void
    {
        $this->output_dir = $output_dir;
    }

    /**
     * @return int
     */
    public function getYearStart(): int
    {
        return $this->year_start;
    }

    /**
     * @param int $year_start
     */
    public function setYearStart(int $year_start): void
    {
        $this->year_start = $year_start;
    }

    /**
     * @return int
     */
    public function getMonthStart(): int
    {
        return $this->month_start;
    }

    /**
     * @param int $month_start
     */
    public function setMonthStart(int $month_start): void
    {
        $this->month_start = $month_start;
    }
}

// /////////////////////////////////////////////////////////////////////////////

$test = new HumbleParser();

// copy cookie string from browser developer mode after logging in to humble (ctrl+shift+i, network analysis, pick any request, copy "Cookie:" from request-headers)
$test->setCookie('csrf_cookie=Uu2p....');

// required if useCache is set to true (strongly recommended)
$test->setCacheDir('/home/user/Desktop/humblecache/');

// required - output csv file is written here
$test->setOutputDir('/home/user/Desktop/');

// cache downloaded data. optional, but strongly recommended
$test->setUseCache(true);

$test->setYearStart(2018); // 4 digits
$test->setMonthStart(7); // numeric, no leading zeros, 7 = july

// just do it!
$test->run();
