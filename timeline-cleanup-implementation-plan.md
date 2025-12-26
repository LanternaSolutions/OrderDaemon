# Timeline Cleanup Implementation Plan

## Overview

This document outlines the comprehensive implementation plan for cleaning up the timeline display in the Order Daemon insight dashboard. The implementation focuses on showing only business-relevant data while preserving technical details in collapsed sections.

## Core Philosophy

**Show only what's essential for business users, merge business sections visually, keep technical details clearly labeled but separate**

## Implementation Phases

### Phase 1: Simplify DisplayAdapter Base Class

**Objective**: Create a unified business data structure and be selective about what gets displayed

**Tasks**:
- Modify `organizeIntoSections()` method to create unified business data structure
- Remove section subtitles for business data - merge primary and additional sections
- Create single business data array instead of separate sections
- Enhance field filtering to ensure only meaningful business data is shown

**WordPress Plugin Checker Compliance**:
- Use WordPress coding standards
- Ensure all methods have proper PHPDoc documentation
- Use WordPress escaping functions (`esc_html`, `esc_attr`)
- Implement defensive checks for WordPress function availability

### Phase 2: Update RegistryTimelineRenderer for Unified Display

**Objective**: Merge primary and business detail sections visually and improve technical section labeling

**Tasks**:
- Modify `renderThreeTierComponent()` to merge primary and business detail sections
- Remove "Additional Details" subtitle from business section
- Create clean, unified business information display
- Update technical section labeling to be clearer and more general
- Change "Developer Details" to "Technical Information"

**WordPress Plugin Checker Compliance**:
- Ensure all HTML output is properly escaped
- Use WordPress translation functions (`__`, `_n`)
- Maintain proper HTML structure and accessibility

### Phase 3: Update Specific Adapters with Selective Extraction

**Objective**: Extract only essential business information for each event type

**OrderEventAdapter**:
- Extract only: Timestamp, Customer (formatted), Payment Method, Amount + Currency
- Additional business context: Order ID, Status
- Technical only: Event Type (debug mode)

**PaymentEventAdapter**:
- Extract only: Timestamp, Payment Method, Amount + Currency, Payment Status
- Additional business context: Checkout Type
- Technical only: Event Type (debug mode)

**RuleExecutionAdapter**:
- Extract only: Timestamp, Rule, Execution Status, Status Change (from → to)
- Technical only: Event Type (debug mode)

**GenericEventAdapter**:
- Show minimal essential info
- Rely on technical section for complete details

**WordPress Plugin Checker Compliance**:
- Use consistent field extraction patterns
- Implement proper error handling
- Ensure all adapters follow the same structure

### Phase 4: Implement Component-Specific Display Rules

**Unified Business Section Approach**:
- Single merged section with all business-relevant fields
- No subtitles or section headers within business data
- Clean key-value layout with consistent styling
- Technical section remains separate with improved labeling

**Technical Section Improvements**:
- Section title: "Technical Information"
- Descriptive text: "Complete raw event data for debugging and analysis"
- Ensure raw JSON payload is properly formatted and escaped - using Prism.js

### Phase 5: Testing and Validation

**Test Cases**:
1. **Unified Business Section**: Verify all business data appears in single section without subtitles
2. **Technical Section Labeling**: Confirm "Technical Information" label is clear and visible
3. **Field Formatting**: Test amount/currency, customer, and status change formatting
4. **Debug Mode**: Verify event_type fields only show when ODCM_DEBUG = true
5. **Status Pills**: Confirm semantic status information with proper right-alignment
6. **WordPress Plugin Checker**: Run full validation and resolve any issues

**WordPress Plugin Checker Compliance**:
- Test with WP_DEBUG enabled
- Verify no PHP notices or warnings
- Ensure all translation functions work correctly
- Validate HTML structure and accessibility

## Visual Design Requirements

### Component Structure
1. **Component Header**: Icon, title, timestamp, right-aligned status pill
2. **Unified Business Section**: All business-relevant fields in clean key-value layout
3. **Technical Section**: Collapsed by default, clear labeling, complete raw data

### Status Pill Implementation
- Right-aligned in component headers
- Semantic color coding (success, error, warning, info)
- Meaningful status information (not just IDs)
- Proper CSS theming using existing design system

### Field Formatting
- **Amount + Currency**: Combined format (e.g., "10.00 USD")
- **Customer**: "Customer: [name] (ID: [id])" format
- **Status Changes**: "[from status] → [to status]" format
- **Timestamps**: Consistent date/time formatting

## Quality Assurance Checklist

- [ ] All business-relevant data shown in unified section without duplicates
- [ ] Technical section has clear, general labeling
- [ ] Debug mode filtering works correctly
- [ ] Status pills show meaningful information and are right-aligned
- [ ] Field formatting is consistent across all components
- [ ] WordPress Plugin Checker passes with no issues
- [ ] No PHP notices or warnings
- [ ] All translation functions work correctly
- [ ] HTML structure is valid and accessible
- [ ] CSS follows WordPress standards

## Implementation Notes

1. **Backward Compatibility**: Ensure existing functionality is preserved
2. **Performance**: No significant performance impact
3. **Maintainability**: Clean, well-documented code
4. **Extensibility**: Easy to add new event types
5. **WordPress Standards**: Follow all WordPress coding best practices

This plan ensures a clean, user-friendly timeline display that shows exactly what business users need to know while keeping all technical details available for debugging purposes.
