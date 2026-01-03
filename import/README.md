# Import Scripts

This folder contains scripts for importing historical callout data into the DLB system.

## Prerequisites

Before running the import script, ensure PhpSpreadsheet is installed:

```bash
composer install
```

## import-callouts.php

Imports callouts and attendance records from an Excel spreadsheet (XLSX format).

### Usage

```bash
php import-callouts.php <brigade-slug> <excel-file> [--dry-run] [--year=YYYY]
```

### Options

- `<brigade-slug>` - The slug of the brigade to import into (e.g., `pukekohe`)
- `<excel-file>` - Path to the Excel file (relative to import/ or absolute)
- `--dry-run` - Show what would be imported without making changes
- `--year=YYYY` - Specify the year (default: 2025). Used to determine sheet name (e.g., `AllCalls25`)

### Examples

```bash
# Dry run to see what would be imported
php import-callouts.php pukekohe 2025.xlsx --dry-run

# Actually import the data
php import-callouts.php pukekohe 2025.xlsx

# Import 2026 data (uses AllCalls26 sheet)
php import-callouts.php pukekohe 2026.xlsx --year=2026
```

### Excel File Format

The script expects an Excel file with a sheet named `AllCalls{YY}` (e.g., `AllCalls25` for 2025) containing:

| Column | Content |
|--------|---------|
| C | Time |
| E | Date |
| I | Event Number (e.g., F4120384) |
| M | Event Type |
| O | Incident Info |
| T | Address |
| X onwards | Member columns (e.g., "CFO John Robinson", "DCFO Bharat Ravji") |

Member columns should have:
- Header format: `RANK FirstName LastName` (e.g., "QFF Ian Chapman")
- Cell value `I` = attended, `.` = not attended

### What the script does

1. Reads the specified Excel sheet
2. For each row with a valid Event Number (starting with 'F'):
   - Creates a callout record (or skips if it already exists)
   - Creates attendance records for members marked with 'I'
3. For members not found in the database:
   - Creates them as **inactive** members
   - You can activate them later through the admin interface if needed

### Truck/Position for imported attendance

Attendance records need a truck and position. The script will:
1. Look for an existing "Station" truck with a "Standby" position
2. If not found, create an "Imported" truck with an "Imported" position

### Notes

- Always run with `--dry-run` first to preview changes
- The script is idempotent - running it twice won't duplicate data
- New members are created as inactive (is_active = 0)
- Callouts are created with status = 'submitted'
