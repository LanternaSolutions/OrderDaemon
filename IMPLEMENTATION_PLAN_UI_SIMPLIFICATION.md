# UI Rendering Simplification - Implementation Plan

## 🎯 Objective
Simplify AuditLogEndpoint rendering from 7+ decision paths to 1 decision point while preserving all 19 specialized renderers.

## ✅ What's Already Working (Keep)
- `PayloadComponentRegistry.php` - All 19 renderer definitions ✅
- `apply_process_id_consolidation()` - Backend grouping logic ✅
- All 19 specialized renderer classes in `src/View/PayloadRenderer/` ✅
- Frontend Alpine.js detection logic ✅

## 📋 Implementation Checklist

### Phase 1: Core Backend Simplification
- [ ] Backup current `render_components()` method
- [ ] Implement simplified `render_components()` with single decision point
- [ ] Create `render_process_timeline()` helper
- [ ] Create `render_individual_entry()` helper
- [ ] Create `extract_components_from_event()` helper
- [ ] Create `extract_components_from_payload()` helper
- [ ] Create `create_synthetic_component()` helper
- [ ] Create `render_empty_payload_fallback()` helper
- [ ] Ensure `render_component_timeline()` works with unified flow

### Phase 2: Cleanup Obsolete Code
- [ ] Remove `is_frontend_consolidated_entry()`
- [ ] Remove `render_frontend_consolidated_entry()`
- [ ] Remove `detect_lifecycle_group()`
- [ ] Remove `render_lifecycle_group()`
- [ ] Remove `render_consolidated_entry()`
- [ ] Remove `extract_components_from_timeline_event()`
- [ ] Remove `group_components_for_rendering()`
- [ ] Remove `render_consolidated_component_timeline()`
- [ ] Remove `is_custom_error_event()`
- [ ] Remove `render_custom_error_event()`
- [ ] Remove `render_simple_timeline_events()`
- [ ] Remove `group_logs_by_families()`
- [ ] Remove `render_grouped_timeline()`
- [ ] Remove `filter_consolidated_results()`

### Phase 3: Testing
- [ ] Test process timeline (consolidated view)
- [ ] Test individual entry
- [ ] Test debug filtering
- [ ] Test empty payloads
- [ ] Verify all 19 renderers still work
- [ ] Cross-browser testing

### Phase 4: Documentation
- [ ] Update code comments
- [ ] Document new architecture
- [ ] Create testing guide

## 🚀 Implementation Status
Started: 2025-10-20 22:36
Status: In Progress
