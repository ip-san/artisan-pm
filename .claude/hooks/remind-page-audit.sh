#!/usr/bin/env bash
# After a view or stylesheet edit, remind Claude that a stale CSS build leaves new classes unstyled.
file=$(jq -r '.tool_input.file_path // empty')

case "$file" in
    */resources/views/* | */resources/css/*)
        jq -n '{hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: "A view or stylesheet changed. Before reporting the UI as done: rebuild the CSS (vendor/bin/sail npm run build) and run the page audit (vendor/bin/sail artisan test --compact tests/Browser/PageAuditTest.php)."}}'
        ;;
esac

exit 0
