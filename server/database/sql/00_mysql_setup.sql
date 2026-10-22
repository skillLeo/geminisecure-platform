-- =====================================================================
-- GeminiSecure — MySQL roles and central database
-- Implements 06_OVERRIDE §5. Run once as root:
--
--   mysql -u root -h 127.0.0.1 -P 3307 < database/sql/00_mysql_setup.sql
--
-- Requires MySQL 8.0+ (utf8mb4_0900_ai_ci does not exist in MariaDB).
--
-- The two-user split is load-bearing:
--   gs_owner  runs migrations and owns schema. Never used by the app.
--   gs_app    is what Laravel connects as, on gs_platform ONLY.
--
-- gs_app must NEVER hold a grant on any gs_estate_* database, and never a
-- wildcard such as gs_estate_%.*. Estate databases are provisioned by
-- stancl/tenancy with a DEDICATED user each, so that a cross-estate query
-- fails on a GRANT error rather than returning the wrong rows.
--
-- Idempotent: safe to re-run.
-- =====================================================================

CREATE USER IF NOT EXISTS 'gs_owner'@'localhost' IDENTIFIED BY 'change_me_owner';
CREATE USER IF NOT EXISTS 'gs_owner'@'127.0.0.1' IDENTIFIED BY 'change_me_owner';
CREATE USER IF NOT EXISTS 'gs_app'@'localhost'   IDENTIFIED BY 'change_me_app';
CREATE USER IF NOT EXISTS 'gs_app'@'127.0.0.1'   IDENTIFIED BY 'change_me_app';

CREATE DATABASE IF NOT EXISTS gs_platform
    CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Owner: full control of the central database.
GRANT ALL PRIVILEGES ON gs_platform.* TO 'gs_owner'@'localhost';
GRANT ALL PRIVILEGES ON gs_platform.* TO 'gs_owner'@'127.0.0.1';

-- Owner also needs to create estate databases and their users at
-- provisioning time. Scoped to exactly those two abilities.
GRANT CREATE USER ON *.* TO 'gs_owner'@'localhost' WITH GRANT OPTION;
GRANT CREATE USER ON *.* TO 'gs_owner'@'127.0.0.1' WITH GRANT OPTION;
-- Estate databases.
--
-- Quoting note, learned the hard way: `gs\_estate\_%` in backticks produces a
-- grant on a database whose name literally contains backslashes, matching
-- nothing; single quotes are a syntax error for a database identifier; and the
-- unquoted escaped form is intercepted by the mysql CLI as the client command
-- `\_`. Backticks without escapes is the form that works, at the cost of `_`
-- being a single-character wildcard rather than a literal.
--
-- That looseness is acceptable and bounded: the pattern still cannot match
-- gs_platform, and no database outside the gs?estate? shape will ever exist.
-- It is gs_OWNER that holds this, never gs_app.
GRANT ALL PRIVILEGES ON `gs_estate_%`.* TO 'gs_owner'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON `gs_estate_%`.* TO 'gs_owner'@'127.0.0.1' WITH GRANT OPTION;

-- Provisioning checks whether an estate's user already exists before creating
-- it. Read-only, and on that single table rather than the whole mysql schema.
GRANT SELECT ON mysql.user TO 'gs_owner'@'localhost';
GRANT SELECT ON mysql.user TO 'gs_owner'@'127.0.0.1';

-- Application: DML on the central database and nothing else.
GRANT SELECT, INSERT, UPDATE, DELETE ON gs_platform.* TO 'gs_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON gs_platform.* TO 'gs_app'@'127.0.0.1';

FLUSH PRIVILEGES;
