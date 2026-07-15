#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/chat-extraction}"
STAMP="$(date +%Y%m%d%H%M%S)"
ARCHIVE="$BACKUP_DIR/next-chat-tables-$STAMP.dump"
PASSWORD_FILE="/root/.chat-db-password"
CHAT_TABLES=(chat_rooms chat_messages chat_room_users chat_moderation_actions chat_message_reports)

sudo install -d -o postgres -g postgres -m 700 "$BACKUP_DIR"

if sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='chat'" | grep -q 1; then
  table_count="$(sudo -u postgres psql -d chat -Atqc "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'chat_%'")"
  if [ "$table_count" -gt 0 ]; then
    echo "chat database already contains chat tables; refusing to overwrite it" >&2
    exit 1
  fi
fi

dump_args=(--format=custom --no-owner --dbname=next --file="$ARCHIVE")
for table in "${CHAT_TABLES[@]}"; do
  dump_args+=(--table="public.$table")
done

echo "Creating recoverable production snapshot: $ARCHIVE"
sudo -u postgres pg_dump "${dump_args[@]}"
sudo chmod 600 "$ARCHIVE"

if ! sudo test -s "$PASSWORD_FILE"; then
  sudo sh -c "umask 077; openssl rand -hex 32 > '$PASSWORD_FILE'"
fi

db_password="$(sudo cat "$PASSWORD_FILE")"
if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='chat'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE chat LOGIN PASSWORD '$db_password'"
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "ALTER ROLE chat LOGIN PASSWORD '$db_password'"
fi

if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='chat'" | grep -q 1; then
  sudo -u postgres createdb --owner=chat chat
fi

echo "Restoring production Chat tables into the independent database"
sudo -u postgres pg_restore --exit-on-error --no-owner --role=chat --dbname=chat "$ARCHIVE"

sudo -u postgres psql -v ON_ERROR_STOP=1 --dbname=chat <<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    id bigserial PRIMARY KEY,
    migration varchar(255) NOT NULL,
    batch integer NOT NULL
);

CREATE TABLE IF NOT EXISTS chat_users (
    id bigint PRIMARY KEY,
    name varchar(255) NOT NULL,
    email varchar(255),
    is_admin boolean NOT NULL DEFAULT false,
    permissions json,
    last_synced_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

ALTER TABLE migrations OWNER TO chat;
ALTER SEQUENCE migrations_id_seq OWNER TO chat;
ALTER TABLE chat_users OWNER TO chat;
GRANT USAGE, CREATE ON SCHEMA public TO chat;

INSERT INTO migrations (migration, batch)
SELECT migration, 1
FROM (VALUES
    ('2025_07_25_040000_create_chat_users_table'),
    ('2025_07_25_045921_create_chat_rooms_table'),
    ('2025_07_25_050109_create_chat_messages_table'),
    ('2025_07_25_050248_create_chat_room_users_table'),
    ('2025_07_25_050300_create_chat_moderation_actions_table'),
    ('2025_07_25_050302_create_chat_message_reports_table')
) AS expected(migration)
WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migrations.migration = expected.migration
);
SQL

echo "Copying only identities referenced by Chat data"
sudo -u postgres psql --dbname=next -v ON_ERROR_STOP=1 -c "COPY (
    SELECT id, name, email, is_admin,
        CASE WHEN is_admin THEN json_build_array('admin') ELSE '[]'::json END AS permissions,
        now(), now(), now()
    FROM users
    WHERE id IN (
        SELECT created_by FROM chat_rooms
        UNION SELECT user_id FROM chat_messages
        UNION SELECT user_id FROM chat_room_users
        UNION SELECT moderator_id FROM chat_moderation_actions WHERE moderator_id IS NOT NULL
        UNION SELECT target_user_id FROM chat_moderation_actions
        UNION SELECT muted_by FROM chat_room_users WHERE muted_by IS NOT NULL
        UNION SELECT banned_by FROM chat_room_users WHERE banned_by IS NOT NULL
        UNION SELECT reported_by FROM chat_message_reports
        UNION SELECT reviewed_by FROM chat_message_reports WHERE reviewed_by IS NOT NULL
    )
) TO STDOUT WITH (FORMAT csv)" \
  | sudo -u postgres psql --dbname=chat -v ON_ERROR_STOP=1 -c "COPY chat_users
    (id, name, email, is_admin, permissions, last_synced_at, created_at, updated_at)
    FROM STDIN WITH (FORMAT csv)"

echo "Validating production row counts"
for table in "${CHAT_TABLES[@]}"; do
  source_count="$(sudo -u postgres psql --dbname=next -Atqc "SELECT count(*) FROM $table")"
  target_count="$(sudo -u postgres psql --dbname=chat -Atqc "SELECT count(*) FROM $table")"
  echo "$table source=$source_count target=$target_count"
  test "$source_count" = "$target_count"
done

echo "Snapshot retained at $ARCHIVE"
