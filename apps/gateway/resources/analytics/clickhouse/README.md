# Plausible's ClickHouse configuration

These four files are copied unchanged from [plausible/community-edition](https://github.com/plausible/community-edition) at commit `ec6c4da77654`, from its `clickhouse/` directory. The analytics role publishes them on the ClickHouse Process's Node and mounts them read-only where Plausible's `compose.yml` mounts them ([ADR 0142](../../../../../docs/decisions/0142-apply-plausibles-clickhouse-configuration-from-the-analytics-role.md)).

| File | Container path |
| --- | --- |
| `config.d/logs.xml` | `/etc/clickhouse-server/config.d/logs.xml` |
| `config.d/ipv4-only.xml` | `/etc/clickhouse-server/config.d/ipv4-only.xml` |
| `config.d/low-resources.xml` | `/etc/clickhouse-server/config.d/low-resources.xml` |
| `users.d/default-profile-low-resources-overrides.xml` | `/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml` |

To follow a newer Plausible release, copy the files again byte for byte and update the commit above. Do not edit them here.
