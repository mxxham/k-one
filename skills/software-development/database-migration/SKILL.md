---
name: database-migration
description: Database migrations - safe schema changes, data fixes, and migrations
version: 1.0.0
author: Hermes Agent
license: MIT
platforms: [linux, macos, windows]
metadata:
  hermes:
    tags: [database, migration, sql, schema, data-fix]
---

# Database Migration

## Overview

Safe database schema and data modifications while preserving integrity.

**Core principle:** ALWAYS verify current state before making changes. Use transactions. Test migrations on copy first.

## When to Use

- Fixing corrupt seed data
- Schema changes requiring data migration
- Correcting location codes, bay numbers, or rack identifiers
- Bulk data updates with rollback requirements

## The Migration Workflow

### 1. Inspect Current State

BEFORE any migration, understand what exists:

```sql
-- Check table structure
SHOW CREATE TABLE table_name;

-- Check current data
SELECT COUNT(*) as count FROM table_name;
SELECT DISTINCT column FROM table_name ORDER BY column;

-- Check for constraints/indexes
SHOW INDEX FROM table_name;
```

### 2. Identify the Problem

Ask:
- What exactly is wrong? (wrong values, missing rows, extra rows)
- Is it data corruption or logic error?
- What is the correct state?

### 3. Plan the Fix

For data fixes:
- Can existing rows be UPDATED?
- Do we need to DELETE bad data first?
- Do we need to INSERT missing data?
- What preserves referential integrity?

### 4. Create the Migration

SQL migrations should be:
- Idempotent where possible (use INSERT IGNORE, or conditional logic)
- Wrapped in transactions
- Backed by verification queries
- Include BEFORE/AFTER checks

Example pattern:

```sql
USE database_name;

-- Show current state
SELECT 'BEFORE' as state, COUNT(*) as cnt FROM table WHERE condition;

-- Transaction
START TRANSACTION;

-- Delete incorrect data
DELETE FROM table WHERE wrong_condition;

-- Insert/update correct data
INSERT IGNORE INTO table (...) VALUES (...);

-- Commit
COMMIT;

-- Verify
SELECT 'AFTER' as state, COUNT(*) as cnt FROM table WHERE condition;
```

### 5. Verify Migration

```sql
-- Check counts
SELECT COUNT(*) FROM table WHERE condition;

-- Check specific values
SELECT DISTINCT column FROM table WHERE condition ORDER BY column;

-- Check for integrity violations
SELECT * FROM table WHERE key_column IS NULL;
```

## Data Migration Patterns

### Wrong Bay Numbers

When bay numbers are incorrect (e.g., CC35-CC40 instead of CC18-CC34):

1. DELETE the wrong entries
2. INSERT correct entries
3. Verify row counts match expected

### Missing Bays

When bays are completely missing:

1. Calculate expected rows per bay
2. INSERT the missing rows
3. Verify

### Duplicate Data

When rows exist multiple times:

1. DELETE duplicates keeping one copy
2. Verify counts

## Verification Queries

**See `references/verification-queries.md` for common verification patterns.**

## Related Skills

- `systematic-debugging` - For investigating data issues
- `test-driven-development` - For testing migrations