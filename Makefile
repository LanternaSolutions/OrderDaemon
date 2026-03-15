# =============================================================================
# Order Daemon — Developer Makefile
# =============================================================================
# Run `make help` for a list of available targets.

.PHONY: help translations translations-check translations-fill-raw

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

translations: ## Regenerate POT, merge into en_US.po, compile .mo
	@bin/update-translations.sh

translations-check: ## Report untranslated strings without modifying files
	@bin/update-translations.sh --check

translations-fill-raw: ## Auto-fill raw English msgstr values (msgstr = msgid)
	@bin/update-translations.sh --fill-raw
