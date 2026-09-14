---
title: "Your first app"
description: "Add a development Node and verify a page over private HTTPS."
---

# Your first app

This walkthrough helps an operator add one development Node and serve a static page through Orbit's private HTTPS network. Complete [installation](/reference/installation) first. Run the CLI commands on the Gateway as `orbit`, using the connected profile.

The fresh-machine trial has an unresolved serving failure: Caddy cannot traverse the managed account's home directory and returns HTTP 403 even when the App instance is active. This walkthrough is a reproducible trial, not a verified release path. Do not use broad permission changes to work around it.

## Prepare the source

The example uses [MDN's public learning repository](https://github.com/mdn/learning-area), which contains a static test page. It requires no application dependencies, key, database, build command, or GitHub account. The page says `This is my page`.

You can substitute your own public repository and relative web root. Keep credentials out of repository URLs. Private repositories need separately configured Git access on the Gateway and destination Node.

## Add the development Node

Start with a separate, fresh Ubuntu 26.04 machine. Through its trusted provider console, authorize the public key from the Gateway's `/home/orbit/.orbit/ssh/id_ed25519.pub` in the bootstrap account's `~/.ssh/authorized_keys`. Copy only the public key. The account must be root or have passwordless sudo, and its SSH directory and file need modes `0700` and `0600`.

Read the Node's host fingerprint through that same trusted console:

```bash
sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256
```

Use the displayed SHA256 fingerprint in this Gateway CLI command. A network key scan alone cannot establish the machine's identity.

```bash
orbit node:add dev '<NODE_PUBLIC_HOST>' \
  --user=root \
  --role=app-dev \
  --tld=alpha.test \
  --host-key-fingerprint='SHA256:<VERIFIED_FINGERPRINT>'
orbit node:list
```

Replace `root` if your provider uses another bootstrap account. Orbit records the machine architecture, creates its managed account, establishes WireGuard, and provisions the development role. The example assigns an unclustered Node a unique private TLD. Use a TLD that does not conflict with another Node or your local DNS.

Expect the Node and role to become active. Keep its returned numeric ID. Role convergence closes public SSH after private access works, so keep provider-console access. Do not add the machine again under another name to work around a failed step; inspect the stable error code and retry the same input after fixing the cause.

## Create the App instance

Register the example repository with its page directory as the web root:

```bash
orbit app:create hello https://github.com/mdn/learning-area.git \
  --default-branch=main --root=html/introduction-to-html/getting-started
orbit app:list
orbit instance:create <APP_ID> <NODE_ID> default
orbit instance:list
```

Use the IDs returned by your commands. Orbit clones the repository, records its source, creates a Route, and returns the HTTPS URL. With these names, the generated hostname is `hello.alpha.test`. Use the URL in the response as the authority.

An active App instance means Orbit finished provisioning. It does not mean that an application's dependencies, database, or build steps are ready. The [application guide](/domains/applications#handle-provisioning-and-application-errors) describes that boundary for larger applications.

## Verify the page

From the Gateway, query Orbit DNS and request the returned URL with the generated root certificate:

```bash
getent ahostsv4 hello.alpha.test
curl --fail --show-error \
  --cacert /home/orbit/.orbit/ca/root.pem \
  https://hello.alpha.test
orbit doctor --json
```

The hostname must resolve to the managed network, and HTTPS must return the `This is my page` content without `--insecure`. Inspect Doctor's exit code and findings; it reports health but does not repair failures. Open the same URL in a browser on a connected, authorized operator machine that trusts the Gateway root certificate and uses Orbit DNS. A public browser without the VPN cannot reach this private Route.

The first request can return HTTP 401 with Orbit's runtime-starting page. A browser refreshes that page automatically; repeat the curl command after startup completes. This temporary response is not the application's successful response. See [runtime wake](/reference/app-dev-runtime-hibernation#wake).

If DNS fails, inspect the WireGuard link and [private DNS](/reference/private-dns). If HTTPS fails, check the returned hostname, CA trust, Caddy, and Node state. If the request returns the wrong page, inspect the repository branch and relative web root. An active record alone does not satisfy this walkthrough.

Keep the source revision, Node ID, App instance ID, URL, and redacted command results with your trial notes. Use [the feedback route](/reference/alpha#feedback) for a reproducible failure.

## Remove the trial application

For a clean, published checkout, remove the exact App instance you created:

```bash
orbit instance:destroy <INSTANCE_ID>
```

Review [App instance removal](/reference/appinstance-removal) before using force or removing a source with local changes. Removing an App instance does not remove the Node. [Node provisioning](/reference/node-provisioning#remove-a-node) explains the separate guards and public SSH recovery for Node removal.
