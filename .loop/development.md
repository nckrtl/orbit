# ORB-191 development

Flow: discovery

Gateway Node TLD set, change, and clear now inventory affected private Routes, converge generated domain replacements, then commit the Node TLD.

Named checks:
- apps/gateway/tests/Feature/Domain/RouteMutationReconciliationTest.php
- apps/gateway/tests/Feature/Domain/ConvergeRouteActionTest.php
- composer docs-lint
- apps/gateway composer check
