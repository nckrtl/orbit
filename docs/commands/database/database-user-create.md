---
title: "database:user:create"
description: "Create a MySQL user and database through a Node Docker Process, then register or refresh the connection."
---

Create a MySQL user and database through an existing Node-targeted Docker MySQL Process, then register or refresh the connection.

```bash
orbit database:user:create <slug> --process=ID --database=NAME --username=USER --password=SECRET
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `slug` | yes | Lowercase kebab name of at most 63 characters. |

| Option | Meaning |
| --- | --- |
| `--process=ID` | Numeric Process ID of a Node-targeted Docker MySQL Process. |
| `--database=NAME` | Database name. A 1-32 character identifier. |
| `--username=USER` | Username. A 1-32 character identifier. |
| `--password=SECRET` | Password for the created user. |

```bash
orbit database:user:create app --process=12 --database=app --username=app --password=secret
```

The Process must be Node-owned and Docker, use a `mysql` or `mysql-server` image, publish container port `3306`, and store `MYSQL_ROOT_PASSWORD`. The Gateway creates the user through that Process. The CLI does not open SSH. A slug that already names a mysql connection is refreshed. A pgsql or sqlite slug returns `database.slug_conflict`.

The [Database connections reference](/reference/database-connections#create-a-managed-mysql-user) owns the Process checks, stored host and port, and failure codes.
