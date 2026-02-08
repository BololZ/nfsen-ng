<?php
/**
 * Test script to verify RRD functionality and schema compatibility
 */

require_once __DIR__ . '/vendor/autoload.php';

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\datasources\Rrd;

echo "=== RRD Functionality Test ===\n\n";

try {
    // Initialize configuration
    Config::initialize();
    echo "✓ Configuration initialized\n";
    
    // Check if RRD extension is available
    if (!function_exists('rrd_version')) {
        throw new Exception('RRD extension is not available');
    }
    echo "✓ RRD extension available: " . rrd_version() . "\n";
    
    // Test RRD datasource creation
    $rrd = new Rrd();
    echo "✓ RRD datasource instance created\n";
    
    // Test data path generation
    $testSource = 'test_source';
    $dataPath = $rrd->get_data_path($testSource);
    echo "✓ Data path for '{$testSource}': {$dataPath}\n";
    
    // Ensure data directory exists
    $dataDir = dirname($dataPath);
    if (!file_exists($dataDir)) {
        if (!mkdir($dataDir, 0755, true)) {
            throw new Exception("Failed to create data directory: {$dataDir}");
        }
        echo "✓ Created data directory: {$dataDir}\n";
    }
    
    // Test RRD file creation
    if (file_exists($dataPath)) {
        echo "✓ RRD file already exists: {$dataPath}\n";
        
        // Test structure validation
        $validation = $rrd->validateStructure($testSource, 0, true, false);
        if ($validation['valid']) {
            echo "✓ RRD structure is valid\n";
            echo "  - Expected rows: " . ($validation['expected_rows'] ?? 'N/A') . "\n";
            echo "  - Actual rows: " . ($validation['actual_rows'] ?? 'N/A') . "\n";
        } else {
            echo "⚠ RRD structure validation failed: " . $validation['message'] . "\n";
        }
    } else {
        echo "• Creating new RRD file...\n";
        $created = $rrd->create($testSource);
        if ($created) {
            echo "✓ RRD file created successfully\n";
            
            // Verify the file was created
            if (file_exists($dataPath)) {
                $fileSize = filesize($dataPath);
                echo "✓ RRD file exists, size: " . format_bytes($fileSize) . "\n";
                
                // Test reading the RRD info
                $info = rrd_info($dataPath);
                if ($info !== false) {
                    echo "✓ RRD file is readable\n";
                    echo "  - RRD version: " . ($info['version'] ?? 'unknown') . "\n";
                    echo "  - Step: " . ($info['step'] ?? 'unknown') . " seconds\n";
                    echo "  - DS count: " . count(preg_grep('/^ds\[/', array_keys($info))) . "\n";
                    echo "  - RRA count: " . count(preg_grep('/^rra\[/', array_keys($info))) . "\n";
                } else {
                    echo "⚠ Could not read RRD info: " . rrd_error() . "\n";
                }
            } else {
                echo "✗ RRD file was not created at expected path\n";
            }
        } else {
            echo "✗ Failed to create RRD file\n";
        }
    }
    
    // Test data writing (simulated)
    echo "\n=== Testing Data Writing ===\n";
    $testData = [
        'fields' => [
            'flows' => 100,
            'flows_tcp' => 60,
            'flows_udp' => 30,
            'flows_icmp' => 5,
            'flows_other' => 5,
            'packets' => 5000,
            'packets_tcp' => 3000,
            'packets_udp' => 1500,
            'packets_icmp' => 250,
            'packets_other' => 250,
            'bytes' => 1000000,
            'bytes_tcp' => 600000,
            'bytes_udp' => 300000,
            'bytes_icmp' => 50000,
            'bytes_other' => 50000,
        ],
        'source' => $testSource,
        'port' => 0,
        'date_iso' => date('Ymd\THis'),
        'date_timestamp' => time(),
    ];
    
    $written = $rrd->write($testData);
    if ($written) {
        echo "✓ Data written successfully to RRD file\n";
        
        // Test reading back the data
        $lastUpdate = $rrd->last_update($testSource);
        echo "✓ Last update timestamp: " . date('Y-m-d H:i:s', $lastUpdate) . "\n";
    } else {
        echo "✗ Failed to write data to RRD file\n";
    }
    
    // Test graph data retrieval
    echo "\n=== Testing Graph Data Retrieval ===\n";
    $now = time();
    $oneHourAgo = $now - 3600;
    
    $graphData = $rrd->get_graph_data(
        $oneHourAgo,
        $now,
        [$testSource],
        ['tcp', 'udp', 'icmp', 'other'],
        [],
        'flows',
        'sources'
    );
    
    if (is_array($graphData)) {
        echo "✓ Graph data retrieved successfully\n";
        echo "  - Start: " . date('Y-m-d H:i:s', $graphData['start']) . "\n";
        echo "  - End: " . date('Y-m-d H:i:s', $graphData['end']) . "\n";
        echo "  - Step: " . $graphData['step'] . " seconds\n";
        echo "  - Data points: " . count($graphData['data']) . "\n";
        echo "  - Legends: " . implode(', ', $graphData['legend']) . "\n";
    } else {
        echo "✗ Failed to retrieve graph data: " . $graphData . "\n";
    }
    
    echo "\n=== Test Complete ===\n";
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

/**
 * Format bytes for human readability
 */
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}