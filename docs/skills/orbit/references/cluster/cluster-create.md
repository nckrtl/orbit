---
title: "cluster:create"
description: "Create a Cluster, optionally with a TLD."
---

# cluster:create

Create a Cluster in the `inactive` state.

```bash
orbit cluster:create <name> [--tld=TLD]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Unique Cluster name. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--tld=TLD` | none | Development TLD as one DNS label, such as `test`. An active Cluster with this TLD owns generated development Route domains for its members. |

```bash
orbit cluster:create lab --tld=test
```

The CLI refuses a TLD that is not one lowercase DNS label with `cluster.tld_invalid` before it sends a request. Cluster TLDs are unique among Clusters; an inactive Cluster may share a TLD with a Node that later joins it.
