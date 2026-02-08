<?php

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\vendor\ProgressBar;

class Import {
    private readonly Debug $d;
    private readonly bool $cli;
    private bool $verbose = false;
    private bool $force = false;
    private bool $quiet = false;
    private bool $processPorts = false;
    private bool $processPortsBySource = false;
    private bool $checkLastUpdate = false;

    public function __construct() {
        $this->d = Debug::getInstance();
        $this->cli = (\PHP_SAPI === 'cli');
        $this->d->setDebug($this->verbose);
    }

    /**
     * @throws \Exception
     */
    public function start(\DateTime $dateStart): void {
        $sources = Config::$cfg['general']['sources'];
        $processedSources = 0;

        // Validate RRD structure before starting import (if using RRD datasource and not in force mode)
        if ($this->force === false && Config::$db instanceof Rrd && !empty($sources)) {
            if ($this->cli === true && $this->quiet === false) {
                echo PHP_EOL . 'Validating RRD structure...' . PHP_EOL;
            }
            Config::$db->validateStructure($sources[0], 0, true, $this->quiet);
        }

        // if in force mode, reset existing data
        if ($this->force === true) {
            if ($this->cli === true) {
                echo 'Resetting existing data...' . PHP_EOL;
            }
            Config::$db->reset([]);
        }

        // start progress bar (CLI only)
        $daysTotal = ((int) $dateStart->diff(new \DateTime())->format('%a') + 1) * \count($sources);
        if ($this->cli === true && $this->quiet === false) {
            echo PHP_EOL . ProgressBar::start($daysTotal, 'Processing ' . \count($sources) . ' sources...');
        }

        // process each source, e.g. gateway, mailserver, etc.
        foreach ($sources as $nr => $source) {
            $sourcePath = Config::$cfg['nfdump']['profiles-data'] . \DIRECTORY_SEPARATOR . Config::$cfg['nfdump']['profile'];
            if (!file_exists($sourcePath)) {
                throw new \Exception('Could not read nfdump profile directory ' . $sourcePath);
            }
            if ($this->cli === true && $this->quiet === false) {
                echo PHP_EOL . 'Processing source ' . $source . ' (' . ($nr + 1) . '/' . \count($sources) . ')...' . PHP_EOL;
            }

            $date = clone $dateStart;

            // check if we want to continue a stopped import
            // assumes the last update of a source is similar to the last update of its ports...
            $lastUpdateDb = Config::$db->last_update($source);

            $lastUpdate = null;
            if ($lastUpdateDb > 0) {
                $lastUpdate = (new \DateTime())->setTimestamp($lastUpdateDb);
            }

            if ($this->force === false && isset($lastUpdate)) {
                $daysSaved = (int) $date->diff($lastUpdate)->format('%a');
                $daysTotal -= $daysSaved;
                if ($this->quiet === false) {
                    $this->d->log('Last update: ' . $lastUpdate->format('Y-m-d H:i'), LOG_INFO);
                }
                if ($this->cli === true && $this->quiet === false) {
                    ProgressBar::setTotal($daysTotal);
                }

                // set progress to the date when the import was stopped
                $date->setTimestamp($lastUpdateDb);
                $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }

            // iterate from $datestart until today
            while ((int) $date->format('Ymd') <= (int) (new \DateTime())->format('Ymd')) {
                $scan = [$sourcePath, $source, $date->format('Y'), $date->format('m'), $date->format('d')];
                $scanPath = implode(\DIRECTORY_SEPARATOR, $scan);

                // set date to tomorrow for next iteration
                $date->modify('+1 day');

                // if no data exists for current date  (e.g. .../2017/03/03)
                if (!file_exists($scanPath)) {
                    $this->d->dpr($scanPath . ' does not exist!');
                    if ($this->cli === true && $this->quiet === false) {
                        echo ProgressBar::next(1);
                    }

                    continue;
                }

                // scan path
                $this->d->log('Scanning path ' . $scanPath, LOG_INFO);
                $scanFiles = scandir($scanPath);

                if ($this->cli === true && $this->quiet === false) {
                    echo ProgressBar::next(1, 'Scanning ' . $scanPath . '...');
                }

                foreach ($scanFiles as $file) {
                    if (\in_array($file, ['.', '..'], true)) {
                        continue;
                    }

                    try {
                        // parse date of file name to compare against last_update
                        $timestamp = $this->extractTimestampFromFilename($file);
                        $fileDatetime = new \DateTime($timestamp);
                    } catch (\RuntimeException $e) {
                        $this->d->log('Caught exception: ' . $e->getMessage(), LOG_DEBUG);

                        continue;
                    }

                    // compare file name date with last update
                    if ($fileDatetime <= $lastUpdate) {
                        continue;
                    }

                    // let nfdump parse each nfcapd file
                    $statsPath = implode(\DIRECTORY_SEPARATOR, \array_slice($scan, 2, 5)) . \DIRECTORY_SEPARATOR . $file;

                    try {
                        // fill source.rrd
                        $this->writeSourceData($source, $statsPath);

                        // write general port data (queries data for all sources, should only be executed when data for all sources exists...)
                        if ($this->processPorts === true && $nr === \count($sources) - 1) {
                            $this->writePortsData($statsPath);
                        }

                        // if enabled, process ports per source as well (source_80.rrd)
                        if ($this->processPortsBySource === true) {
                            $this->writePortsData($statsPath, $source);
                        }
                    } catch (\Exception $e) {
                        $this->d->log('Caught exception: ' . $e->getMessage(), LOG_WARNING);
                    }
                }
            }
            ++$processedSources;
        }
        if ($processedSources === 0) {
            $this->d->log('Import did not process any sources.', LOG_WARNING);
        }
        if ($this->cli === true && $this->quiet === false) {
            echo ProgressBar::finish();
        }
    }

    /**
     * Import a single nfcapd file.
     */
    public function importFile(string $file, string $source, bool $last): void {
        try {
            $this->d->log('Importing file ' . $file . ' (' . $source . '), last=' . (int) $last, LOG_INFO);

            // Ensure RRD files exist before writing data
            $this->ensureRrdFilesExist($source);

            // fill source.rrd
            $this->writeSourceData($source, $file);

            // write general port data (not depending on source, so only executed per port)
            if ($last === true) {
                $this->writePortsData($file);
            }

            // if enabled, process ports per source as well (source_80.rrd)
            if ($this->processPorts === true) {
                $this->writePortsData($file, $source);
            }
        } catch (\Exception $e) {
            $this->d->log('Caught exception: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Ensure RRD files exist for the given source and all configured ports.
     */
    private function ensureRrdFilesExist(string $source): void {
        try {
            // Create main source RRD file
            if (!file_exists(Config::$db->get_data_path($source))) {
                $this->d->log('Creating RRD file for source: ' . $source, LOG_INFO);
                Config::$db->create($source);
            }

            // Create port-specific RRD files if ports are configured
            $ports = Config::$cfg['general']['ports'];
            foreach ($ports as $port) {
                $rrdPath = Config::$db->get_data_path($source, $port);
                if (!file_exists($rrdPath)) {
                    $this->d->log('Creating RRD file for source:port ' . $source . ':' . $port, LOG_INFO);
                    Config::$db->create($source, $port);
                }
            }
        } catch (\Exception $e) {
            $this->d->log('Error ensuring RRD files exist: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Extract timestamp from nfcapd filename (handles both with and without timezone offset).
     */
    private function extractTimestampFromFilename(string $filename): string {
        if (preg_match('/nfcapd\.([0-9]{12})([+-][0-9]{4})?$/', $filename, $matches)) {
            return $matches[1];
        }
        throw new \RuntimeException('Could not extract timestamp from filename: ' . $filename);
    }

    /**
     * Check if db is free to update (some databases only allow inserting data at the end).
     *
     * @throws \Exception
     */
    public function dbUpdatable(string $file, string $source = '', int $port = 0): bool {
        if ($this->checkLastUpdate === false) {
            return true;
        }

        // parse capture file's datetime. can't use filemtime as we need the datetime in the file name.
        try {
            $timestamp = $this->extractTimestampFromFilename($file);
            $fileDatetime = new \DateTime($timestamp);
        } catch (\RuntimeException $e) {
            return false; // nothing to import
        }

        // get last updated time from database
        $lastUpdateDb = Config::$db->last_update($source, $port);
        $lastUpdate = null;
        if ($lastUpdateDb !== 0) {
            $lastUpdate = new \DateTime();
            $lastUpdate->setTimestamp($lastUpdateDb);
        }

        // prevent attempt to import the same file again
        return $fileDatetime > $lastUpdate;
    }

    public function setVerbose(bool $verbose): void {
        if ($verbose === true) {
            $this->d->setDebug(true);
        }
        $this->verbose = $verbose;
    }

    public function setProcessPorts(bool $processPorts): void {
        $this->processPorts = $processPorts;
    }

    public function setForce(bool $force): void {
        $this->force = $force;
    }

    public function setQuiet(bool $quiet): void {
        $this->quiet = $quiet;
    }

    public function setProcessPortsBySource($processPortsBySource): void {
        $this->processPortsBySource = $processPortsBySource;
    }

    public function setCheckLastUpdate(bool $checkLastUpdate): void {
        $this->checkLastUpdate = $checkLastUpdate;
    }

    /**
     * @throws \Exception
     */
    private function writeSourceData(string $source, string $statsPath): bool {
        // set options and get netflow summary statistics (-I)
        $nfdump = Nfdump::getInstance();
        $nfdump->reset();
        $nfdump->setOption('-I', null);
        $nfdump->setOption('-M', $source);
        $nfdump->setOption('-r', $statsPath);

        if ($this->dbUpdatable($statsPath, $source) === false) {
            return false;
        }

        try {
            $input = $nfdump->execute();
        } catch (\Exception $e) {
            $this->d->log('Exception: ' . $e->getMessage(), LOG_WARNING);

            return false;
        }

        $timestamp = $this->extractTimestampFromFilename($statsPath);
        $date = new \DateTime($timestamp);
        
        // Initialize all expected fields to ensure RRD gets complete data structure
        $data = [
            'fields' => [
                'flows' => 0,
                'flows_tcp' => 0,
                'flows_udp' => 0,
                'flows_icmp' => 0,
                'flows_other' => 0,
                'packets' => 0,
                'packets_tcp' => 0,
                'packets_udp' => 0,
                'packets_icmp' => 0,
                'packets_other' => 0,
                'bytes' => 0,
                'bytes_tcp' => 0,
                'bytes_udp' => 0,
                'bytes_icmp' => 0,
                'bytes_other' => 0,
            ],
            'source' => $source,
            'port' => 0,
            'date_iso' => $date->format('Ymd\THis'),
            'date_timestamp' => $date->getTimestamp(),
        ];
        // $input data is an array of lines looking like this:
        // flows_tcp: 323829
        foreach ($input as $i => $line) {
            if (!\is_string($line)) {
                $this->d->log('Got no output of previous command', LOG_DEBUG);
            }
            if ($i === 0) {
                continue;
            } // skip nfdump command
            if (!preg_match('/:/', (string) $line)) {
                continue;
            } // skip invalid lines like error messages
            [$type, $value] = explode(': ', (string) $line);

            // parse all flow statistics including protocol-specific ones
            if (preg_match('/^(flows|packets|bytes)(?:_(tcp|udp|icmp|other))?/i', $type, $matches)) {
                $baseField = strtolower($matches[1]);
                $protocol = isset($matches[2]) ? strtolower($matches[2]) : '';
                
                if ($protocol) {
                    $fieldName = $baseField . '_' . $protocol;
                } else {
                    $fieldName = $baseField;
                }
                
                // Only set the field if it exists in our data structure
                if (isset($data['fields'][$fieldName])) {
                    $data['fields'][$fieldName] = (int) $value;
                }
            }
        }

        // write to database
        if (Config::$db->write($data) === false) {
            throw new \Exception('Error writing to ' . $statsPath);
        }

        return true;
    }

    /**
     * @throws \Exception
     */
    private function writePortsData(string $statsPath, string $source = ''): bool {
        $ports = Config::$cfg['general']['ports'];

        foreach ($ports as $port) {
            $this->writePortData($port, $statsPath, $source);
        }

        return true;
    }

    /**
     * @throws \Exception
     */
    private function writePortData(int $port, string $statsPath, string $source = ''): bool {
        $sources = Config::$cfg['general']['sources'];

        // set options and get netflow statistics
        $nfdump = Nfdump::getInstance();
        $nfdump->reset();

        if (empty($source)) {
            // if no source is specified, get data for all sources
            $nfdump->setOption('-M', implode(':', $sources));
            if ($this->dbUpdatable($statsPath, '', $port) === false) {
                return false;
            }
        } else {
            $nfdump->setOption('-M', $source);
            if ($this->dbUpdatable($statsPath, $source, $port) === false) {
                return false;
            }
        }

        $nfdump->setFilter('dst port ' . $port);
        $nfdump->setOption('-s', 'dstport:p');
        $nfdump->setOption('-r', $statsPath);

        try {
            $input = $nfdump->execute();
        } catch (\Exception $e) {
            $this->d->log('Exception: ' . $e->getMessage(), LOG_WARNING);

            return false;
        }

        // parse and turn into usable data

        $timestamp = $this->extractTimestampFromFilename($statsPath);
        $date = new \DateTime($timestamp);
        
        // Initialize all expected fields to ensure RRD gets complete data structure
        $data = [
            'fields' => [
                'flows' => 0,
                'flows_tcp' => 0,
                'flows_udp' => 0,
                'flows_icmp' => 0,
                'flows_other' => 0,
                'packets' => 0,
                'packets_tcp' => 0,
                'packets_udp' => 0,
                'packets_icmp' => 0,
                'packets_other' => 0,
                'bytes' => 0,
                'bytes_tcp' => 0,
                'bytes_udp' => 0,
                'bytes_icmp' => 0,
                'bytes_other' => 0,
            ],
            'source' => $source,
            'port' => $port,
            'date_iso' => $date->format('Ymd\THis'),
            'date_timestamp' => $date->getTimestamp(),
        ];

        // process protocols
        // headers: ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ipbs,ibpp
        foreach ($input as $i => $line) {
            if (!\is_array($line) && $line instanceof \Countable === false) {
                continue;
            } // skip anything uncountable
            if (\count($line) !== 14) {
                continue;
            } // skip anything invalid
            if ($line[0] === 'ts') {
                continue;
            } // skip header

            $proto = strtolower((string) $line[3]);

            // add protocol-specific
            $data['fields']['flows_' . $proto] = (int) $line[5];
            $data['fields']['packets_' . $proto] = (int) $line[7];
            $data['fields']['bytes_' . $proto] = (int) $line[9];

            // add to overall stats
            $data['fields']['flows'] += (int) $line[5];
            $data['fields']['packets'] += (int) $line[7];
            $data['fields']['bytes'] += (int) $line[9];
        }

        // write to database
        if (Config::$db->write($data) === false) {
            throw new \Exception('Error writing to ' . $statsPath);
        }

        return true;
    }
}
