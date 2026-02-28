#!/usr/bin/env bash
# Integration tests for the Access100 subscription API flow.
# Runs sequentially; exits non-zero if any test fails.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
BASE_URL="${1:-http://localhost:8082/api/v1}"
API_KEY="${2:-${API_KEY:-}}"

if [[ -z "$API_KEY" ]]; then
    echo "Usage: $0 [base_url] [api_key]"
    echo "       Or set the API_KEY environment variable."
    exit 1
fi

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
RESET='\033[0m'

PASS_COUNT=0
FAIL_COUNT=0

pass() {
    echo -e "  ${GREEN}PASS${RESET}  $1"
    (( PASS_COUNT++ )) || true
}

fail() {
    echo -e "  ${RED}FAIL${RESET}  $1"
    (( FAIL_COUNT++ )) || true
}

section() {
    echo ""
    echo -e "${BOLD}$1${RESET}"
}

# ---------------------------------------------------------------------------
# Request helpers
# All functions write response body to stdout; HTTP status to $HTTP_STATUS.
# ---------------------------------------------------------------------------
HTTP_STATUS=""

get_public() {
    local url="$BASE_URL$1"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" "$url")
    cat /tmp/a100_response.json
}

get_authed() {
    local url="$BASE_URL$1"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -H "X-API-Key: $API_KEY" "$url")
    cat /tmp/a100_response.json
}

post_authed() {
    local url="$BASE_URL$1"
    local body="$2"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -X POST \
        -H "X-API-Key: $API_KEY" \
        -H "Content-Type: application/json" \
        -d "$body" "$url")
    cat /tmp/a100_response.json
}

post_no_key() {
    local url="$BASE_URL$1"
    local body="$2"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        -d "$body" "$url")
    cat /tmp/a100_response.json
}

patch_token() {
    local path="$1"
    local token="$2"
    local body="$3"
    local url="$BASE_URL${path}?token=${token}"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -X PATCH \
        -H "X-API-Key: $API_KEY" \
        -H "Content-Type: application/json" \
        -d "$body" "$url")
    cat /tmp/a100_response.json
}

put_token() {
    local path="$1"
    local token="$2"
    local body="$3"
    local url="$BASE_URL${path}?token=${token}"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -X PUT \
        -H "X-API-Key: $API_KEY" \
        -H "Content-Type: application/json" \
        -d "$body" "$url")
    cat /tmp/a100_response.json
}

delete_token() {
    local path="$1"
    local token="$2"
    local url="$BASE_URL${path}?token=${token}"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -X DELETE \
        -H "X-API-Key: $API_KEY" "$url")
    cat /tmp/a100_response.json
}

get_token() {
    local path="$1"
    local token="$2"
    local url="$BASE_URL${path}?token=${token}"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -H "X-API-Key: $API_KEY" "$url")
    cat /tmp/a100_response.json
}

get_no_key() {
    local path="$1"
    local url="$BASE_URL${path}"
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" "$url")
    cat /tmp/a100_response.json
}

get_no_key_with_token() {
    local path="$1"
    local token="$2"
    local url="$BASE_URL${path}?token=${token}"
    # No API key, no manage token authentication — testing missing token scenario
    HTTP_STATUS=$(curl -s -o /tmp/a100_response.json -w "%{http_code}" \
        -H "X-API-Key: $API_KEY" "$url")
    cat /tmp/a100_response.json
}

# ---------------------------------------------------------------------------
# State shared across tests
# ---------------------------------------------------------------------------
SUBSCRIPTION_USER_ID=""
SUBSCRIPTION_MANAGE_TOKEN=""

# ---------------------------------------------------------------------------
# HAPPY PATH TESTS
# ---------------------------------------------------------------------------

section "1. Public endpoints"

body=$(get_public "/health")
api_status=$(echo "$body" | jq -r '.data.status // empty' 2>/dev/null)
if [[ "$HTTP_STATUS" == "200" && -n "$api_status" ]]; then
    pass "Health check returns 200 with status field (status=$api_status)"
else
    fail "Health check — expected 200 with status field, got HTTP $HTTP_STATUS"
fi

body=$(get_public "/stats")
total_meetings=$(echo "$body" | jq -r '.data.total_meetings // empty' 2>/dev/null)
if [[ "$HTTP_STATUS" == "200" && -n "$total_meetings" ]]; then
    pass "Stats check returns 200 with total_meetings field"
else
    fail "Stats check — expected 200 with total_meetings, got HTTP $HTTP_STATUS"
fi

section "2. Authenticated list endpoints"

body=$(get_authed "/meetings")
meetings_data=$(echo "$body" | jq -r '.data // empty' 2>/dev/null)
if [[ "$HTTP_STATUS" == "200" && -n "$meetings_data" ]]; then
    pass "List meetings returns 200 with data array"
else
    fail "List meetings — expected 200 with data array, got HTTP $HTTP_STATUS"
fi

body=$(get_authed "/councils")
councils_data=$(echo "$body" | jq -r '.data // empty' 2>/dev/null)
if [[ "$HTTP_STATUS" == "200" && -n "$councils_data" ]]; then
    pass "List councils returns 200 with data array"
else
    fail "List councils — expected 200 with data array, got HTTP $HTTP_STATUS"
fi

body=$(get_authed "/topics")
topics_data=$(echo "$body" | jq -r '.data // empty' 2>/dev/null)
if [[ "$HTTP_STATUS" == "200" && -n "$topics_data" ]]; then
    pass "List topics returns 200 with data array"
else
    fail "List topics — expected 200 with data array, got HTTP $HTTP_STATUS"
fi

section "3. Subscription lifecycle"

# Create subscription with email channel only (phone not required)
CREATE_BODY='{
  "email": "integration-test@example.com",
  "channels": ["email"],
  "council_ids": [1],
  "frequency": "immediate",
  "source": "integration-test"
}'
body=$(post_authed "/subscriptions" "$CREATE_BODY")

if [[ "$HTTP_STATUS" == "201" ]]; then
    pass "Create subscription returns 201"
else
    fail "Create subscription — expected 201, got HTTP $HTTP_STATUS"
fi

SUBSCRIPTION_USER_ID=$(echo "$body" | jq -r '.data.user_id // empty' 2>/dev/null)
if [[ -n "$SUBSCRIPTION_USER_ID" ]]; then
    pass "Create subscription response contains user_id ($SUBSCRIPTION_USER_ID)"
else
    fail "Create subscription response missing user_id"
fi

SUBSCRIPTION_MANAGE_TOKEN=$(echo "$body" | jq -r '.data.manage_token // empty' 2>/dev/null)
if [[ -n "$SUBSCRIPTION_MANAGE_TOKEN" ]]; then
    pass "Create subscription response contains manage_token"
else
    fail "Create subscription response missing manage_token"
fi

sub_status=$(echo "$body" | jq -r '.data.status // empty' 2>/dev/null)
if [[ "$sub_status" == "pending_confirmation" ]]; then
    pass "Create subscription response status is pending_confirmation"
else
    fail "Create subscription status — expected pending_confirmation, got '$sub_status'"
fi

# Abort remaining lifecycle tests if we have no ID/token
if [[ -z "$SUBSCRIPTION_USER_ID" || -z "$SUBSCRIPTION_MANAGE_TOKEN" ]]; then
    echo ""
    echo -e "${RED}Cannot continue lifecycle tests without user_id and manage_token.${RESET}"
else
    body=$(get_token "/subscriptions/$SUBSCRIPTION_USER_ID" "$SUBSCRIPTION_MANAGE_TOKEN")
    detail_user_id=$(echo "$body" | jq -r '.data.user_id // empty' 2>/dev/null)
    if [[ "$HTTP_STATUS" == "200" && "$detail_user_id" == "$SUBSCRIPTION_USER_ID" ]]; then
        pass "Get subscription details returns 200 with correct user_id"
    else
        fail "Get subscription details — expected 200 with user_id=$SUBSCRIPTION_USER_ID, got HTTP $HTTP_STATUS"
    fi

    PATCH_CHANNELS='{"channels": ["email", "sms"], "phone": "+18085550100"}'
    body=$(patch_token "/subscriptions/$SUBSCRIPTION_USER_ID" "$SUBSCRIPTION_MANAGE_TOKEN" "$PATCH_CHANNELS")
    if [[ "$HTTP_STATUS" == "200" ]]; then
        pass "Update channels to email+sms returns 200"
    else
        fail "Update channels — expected 200, got HTTP $HTTP_STATUS"
    fi

    PATCH_FREQUENCY='{"frequency": "daily"}'
    body=$(patch_token "/subscriptions/$SUBSCRIPTION_USER_ID" "$SUBSCRIPTION_MANAGE_TOKEN" "$PATCH_FREQUENCY")
    if [[ "$HTTP_STATUS" == "200" ]]; then
        pass "Update frequency to daily returns 200"
    else
        fail "Update frequency — expected 200, got HTTP $HTTP_STATUS"
    fi

    PUT_COUNCILS='{"council_ids": [1, 2]}'
    body=$(put_token "/subscriptions/$SUBSCRIPTION_USER_ID/councils" "$SUBSCRIPTION_MANAGE_TOKEN" "$PUT_COUNCILS")
    if [[ "$HTTP_STATUS" == "200" ]]; then
        pass "Replace council list returns 200"
    else
        fail "Replace council list — expected 200, got HTTP $HTTP_STATUS"
    fi

    body=$(delete_token "/subscriptions/$SUBSCRIPTION_USER_ID" "$SUBSCRIPTION_MANAGE_TOKEN")
    if [[ "$HTTP_STATUS" == "200" || "$HTTP_STATUS" == "204" ]]; then
        pass "Delete subscription returns 200 or 204"
    else
        fail "Delete subscription — expected 200 or 204, got HTTP $HTTP_STATUS"
    fi

    # Confirm it is gone / deactivated — re-fetching should be 401 (token invalid
    # after deletion) or the subscriptions list should show inactive entries.
    body=$(get_token "/subscriptions/$SUBSCRIPTION_USER_ID" "$SUBSCRIPTION_MANAGE_TOKEN")
    if [[ "$HTTP_STATUS" == "401" || "$HTTP_STATUS" == "404" ]]; then
        pass "Deleted subscription is inaccessible (HTTP $HTTP_STATUS)"
    elif [[ "$HTTP_STATUS" == "200" ]]; then
        # Accept 200 if all subscriptions show active=false
        any_active=$(echo "$body" | jq '[.data.subscriptions[]?.active] | any' 2>/dev/null || echo "false")
        if [[ "$any_active" == "false" ]]; then
            pass "Deleted subscription confirmed deactivated (all active=false)"
        else
            fail "Deleted subscription still shows active=true entries"
        fi
    else
        fail "Post-delete check — unexpected HTTP $HTTP_STATUS"
    fi
fi

# ---------------------------------------------------------------------------
# ERROR CASE TESTS
# ---------------------------------------------------------------------------

section "4. Error cases"

# Missing API key on a protected endpoint
body=$(get_no_key "/meetings")
if [[ "$HTTP_STATUS" == "401" ]]; then
    pass "Missing API key on GET /meetings returns 401"
else
    fail "Missing API key — expected 401, got HTTP $HTTP_STATUS"
fi

# Missing API key on POST /subscriptions
body=$(post_no_key "/subscriptions" '{"email":"x@x.com","council_ids":[1]}')
if [[ "$HTTP_STATUS" == "401" ]]; then
    pass "Missing API key on POST /subscriptions returns 401"
else
    fail "Missing API key on POST /subscriptions — expected 401, got HTTP $HTTP_STATUS"
fi

# Invalid meeting ID
body=$(get_authed "/meetings/INVALID_MEETING_ID_THAT_DOES_NOT_EXIST_99999")
if [[ "$HTTP_STATUS" == "404" || "$HTTP_STATUS" == "400" ]]; then
    pass "Invalid meeting ID returns 404 or 400 (got $HTTP_STATUS)"
else
    fail "Invalid meeting ID — expected 404 or 400, got HTTP $HTTP_STATUS"
fi

# Invalid subscription body — missing both council_ids and topics
INVALID_BODY='{"email": "test@example.com", "channels": ["email"]}'
body=$(post_authed "/subscriptions" "$INVALID_BODY")
if [[ "$HTTP_STATUS" == "400" ]]; then
    pass "Subscription with no council_ids or topics returns 400"
else
    fail "Invalid subscription body — expected 400, got HTTP $HTTP_STATUS"
fi

# Invalid subscription body — empty channels array
INVALID_CHANNELS_BODY='{"email": "test@example.com", "channels": [], "council_ids": [1]}'
body=$(post_authed "/subscriptions" "$INVALID_CHANNELS_BODY")
if [[ "$HTTP_STATUS" == "400" ]]; then
    pass "Subscription with empty channels returns 400"
else
    fail "Empty channels subscription — expected 400, got HTTP $HTTP_STATUS"
fi

# Missing manage token on subscription manage endpoint (token param omitted)
# Use a syntactically valid user_id but no token param
body=$(get_authed "/subscriptions/1")
if [[ "$HTTP_STATUS" == "401" ]]; then
    pass "Missing manage token on GET /subscriptions/{id} returns 401"
else
    fail "Missing manage token — expected 401, got HTTP $HTTP_STATUS"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

echo ""
echo "─────────────────────────────────────────"
TOTAL=$(( PASS_COUNT + FAIL_COUNT ))
echo -e "  Results: ${GREEN}${PASS_COUNT} passed${RESET} / ${RED}${FAIL_COUNT} failed${RESET} / ${TOTAL} total"
echo "─────────────────────────────────────────"

if [[ "$FAIL_COUNT" -gt 0 ]]; then
    exit 1
fi

exit 0
