# RRD Schema Compatibility Fix

## Problem Summary

The original RRD schema validation was too strict, causing import failures when:
1. Existing RRD files had different `NFSEN_IMPORT_YEARS` settings
2. RRD files were created with different schema parameters
3. The system was unable to continue with functional but non-matching RRD files

## Changes Made

### 1. Modified RRD Validation Logic (`backend/datasources/Rrd.php`)

**Before:** The `validateStructure()` method would fail validation and stop imports when RRD files didn't match the expected schema.

**After:** The method now:
- Allows imports to continue with existing functional RRD files
- Only fails validation when `NFSEN_FORCE_IMPORT=true` is explicitly set
- Provides clear warnings about schema mismatches without breaking functionality
- Maintains backward compatibility with existing installations

**Key Change:**
```php
// Allow import to continue with existing structure if not in force mode
if (getenv('NFSEN_FORCE_IMPORT') !== 'true') {
    return [
        'valid' => true, // Allow continuation with existing structure
        'message' => $message . ' - Continuing with existing structure',
        'expected_rows' => $expectedDailyRows,
        'actual_rows' => $actualRows,
    ];
}
```

### 2. Enhanced Import Process (`backend/common/Import.php`)

**Added:** New `ensureRrdFilesExist()` method that:
- Creates RRD files proactively before attempting to write data
- Handles both source-level and port-specific RRD files
- Provides better error handling and logging
- Prevents import failures due to missing RRD files

**Integration:** The method is called before data writing in `importFile()`.

## Usage Instructions

### For Existing Installations with Schema Mismatches

1. **Continue with existing structure (recommended):**
   ```bash
   # No action needed - the system will automatically continue with existing RRD files
   php backend/cli.php import
   ```

2. **Force recreation of RRD files (if you want to change NFSEN_IMPORT_YEARS):**
   ```bash
   NFSEN_FORCE_IMPORT=true php backend/cli.php -f import
   ```

### For New Installations

1. **Set your desired import years (default: 3):**
   ```bash
   NFSEN_IMPORT_YEARS=5 php backend/cli.php -f import
   ```

2. **For verbose output:**
   ```bash
   php backend/cli.php -v import
   ```

### Docker Usage

Update your docker-compose.yml or environment variables:
```yaml
environment:
  - NFSEN_IMPORT_YEARS=3
  # - NFSEN_FORCE_IMPORT=true  # Only if you need to recreate RRD files
```

## Testing the Fix

A comprehensive test script has been provided (`test_rrd_functionality.php`) that:
- Verifies RRD extension availability
- Tests RRD file creation and validation
- Tests data writing and reading
- Tests graph data retrieval
- Provides detailed output for troubleshooting

Run the test:
```bash
php test_rrd_functionality.php
```

## Troubleshooting

### No Graphs Displayed

1. **Check if RRD files exist:**
   ```bash
   find /path/to/data -name "*.rrd"
   ```

2. **Verify RRD file contents:**
   ```bash
   rrdtool info /path/to/source.rrd
   ```

3. **Check import logs:**
   ```bash
   php backend/cli.php -v import
   ```

### Schema Mismatch Warnings

These are informational and don't prevent functionality. To resolve:
- Either continue with existing structure (recommended)
- Or force recreation: `NFSEN_FORCE_IMPORT=true php backend/cli.php -f import`

## Backward Compatibility

The changes maintain full backward compatibility:
- Existing RRD files continue to work
- No data loss occurs from schema mismatches
- The system gracefully handles configuration changes
- All existing CLI options and functionality remain unchanged

## Performance Impact

Minimal performance impact:
- RRD validation is slightly more permissive but equally fast
- The new `ensureRrdFilesExist()` method only creates missing files
- No impact on graph generation or data retrieval performance

## Future Considerations

For future enhancements, consider:
- Automatic schema migration tools
- RRD file backup/restore functionality
- More detailed schema compatibility reporting
- Web-based RRD management interface

## Support

If you encounter issues:
1. Check the detailed warnings and error messages
2. Run the test script for diagnostics
3. Review the import process with verbose logging
4. Consider forcing RRD recreation if schema issues persist
