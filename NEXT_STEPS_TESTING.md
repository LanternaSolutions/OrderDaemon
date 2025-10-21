# Next Steps: Testing Order Daemon with Live Order

## 🎯 Current Status

- ✅ Rule created: "virtual rule" (ID 12, published)
- ✅ Product exists: "virtual product" (ID 14, published)  
- ✅ Plugin initialized: Components loaded successfully
- ❌ **No audit logs exist** (table has 0 rows)
- ❌ **No evidence of hooks firing** in debug logs

## 🧪 Test Plan: Place a Manual Order

### Step 1: Place Test Order via Frontend

1. Open browser to: `http://localhost:8082/`
2. Add "virtual product" to cart
3. Go to checkout
4. Complete order (use any payment method)
5. Watch the terminal that's running: `docker logs -f order-daemon-devtools-wordpress-1`

### Step 2: Watch for Debug Output

While the terminal is running, you should see output when the order processes:

**Expected to see:**
```
ODCM: Order processing hook fired for order #XX
ODCM: Rule evaluation started
ODCM: Rule 'virtual rule' matched
ODCM: Executing action: Complete Order  
ODCM: Audit log created: log_id=1
```

**If you see nothing:**
- Hooks aren't registered or firing
- Need to check `src/Core/Core.php` for hook registration

### Step 3: Verify Audit Logs Created

After placing the order, check the database:

```bash
docker exec order-daemon-devtools-db-1 mysql -u wordpress -pwordpress wordpress \
  -e "SELECT log_id, timestamp, summary, event_type FROM wp_odcm_audit_log ORDER BY timestamp DESC LIMIT 5;" \
  2>&1 | grep -v "Warning"
```

**Expected:** At least 1 new row
**If empty:** The plugin isn't logging events

### Step 4: Check Dashboard

Refresh the Insight Dashboard at:
`http://localhost:8082/wp-admin/admin.php?page=odcm-insight-dashboard`

**Expected:** Timeline entries appear
**If still empty:** Frontend issue or API issue

## 🔍 Key Questions to Answer

1. **Are hooks registered?**
   - Check `src/Core/Core.php` - should have `add_action('woocommerce_checkout_order_processed', ...)`
   - Enable ODCM_DEBUG to see hook registration

2. **Do hooks fire?**
   - Place order and watch logs
   - Should see "Order processing hook fired" messages

3. **Is logging working?**
   - Even if rule doesn't match, should see audit logs
   - Check for odcm_log_event() calls

## 🛠️ Troubleshooting Commands

```bash
# Watch live logs (keep this running in terminal)
docker logs -f order-daemon-devtools-wordpress-1 2>&1 | grep -i "odcm"

# Check if hooks are in the code
docker exec order-daemon-devtools-wordpress-1 grep -n "add_action.*woocommerce" \
  /var/www/html/wp-content/plugins/order-daemon-core/src/Core/Core.php | head -10

# Enable debugging
docker exec order-daemon-devtools-wordpress-1 bash -c \
  "grep -q 'ODCM_DEBUG' /var/www/html/wp-config.php || \
   echo \"define('ODCM_DEBUG', true);\" >> /var/www/html/wp-config.php"

# Check recent orders
docker exec order-daemon-devtools-db-1 mysql -u wordpress -pwordpress wordpress \
  -e "SELECT ID, post_status, post_date FROM wp_posts WHERE post_type='shop_order' ORDER BY ID DESC LIMIT 3;" \
  2>&1 | grep -v "Warning"
```

## 📝 What to Report Back

After placing a test order, please share:

1. **Console output** from the running `docker logs -f` command
2. **Order status** - Did it auto-complete?
3. **Audit log count** - Any new entries in `wp_odcm_audit_log`?
4. **Dashboard state** - Still empty or showing data?

## 🎯 Expected Outcome

If everything works correctly:
- Order should auto-complete ✅
- Multiple audit log entries created ✅
- Dashboard shows timeline with events ✅
- Can click entries to see details ✅

---

**Current Theory:** The old order (#16) was placed before the rule existed, so it didn't trigger the plugin. A NEW order should work if hooks are registered.

**Action:** Place a new test order via the frontend and monitor the logs.
