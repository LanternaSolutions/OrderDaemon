# Order Daemon Core - Renderer System Implementation Task

## Overview

The Order Daemon Core plugin needs a complete refactoring of its payload rendering system. The analysis phase has been completed, and detailed documentation has been created outlining the new architecture and implementation approach.

## Required Reading

Before beginning implementation, please read these documents in order:

1. `payload-renderer-refactor-summary.md` - Executive summary of the refactoring project
2. `event-type-inventory.md` - Complete catalog of all event types in the system
3. `event-data-structures.md` - Detailed analysis of data structures for each event type
4. `event-business-value.md` - Business value analysis of each event type
5. `event-ui-design.md` - UI design specifications for each event type
6. `renderer-consolidation-plan.md` - Technical plan for consolidating renderers

These documents contain all necessary context about:
- The event types that need to be rendered
- Their data structures and business importance
- The UI design patterns to be used
- The new renderer architecture

## Implementation Task

Create a new, simplified renderer system following these specifications:

### Core Components to Implement

1. **BaseRenderer Class**
   - Location: `src/View/PayloadRenderer/BaseRenderer.php`
   - Abstract class implementing Template Method Pattern
   - Provides shared helper methods and core rendering logic
   - See `renderer-consolidation-plan.md` for detailed class structure

2. **Specialized Renderers**
   - Create 5 new renderer classes extending BaseRenderer:
     - RuleRenderer
     - PaymentRenderer
     - OrderRenderer
     - SystemRenderer
     - AnalysisRenderer
   - Each handles specific event types as detailed in `renderer-consolidation-plan.md`

3. **Registry Updates**
   - Simplify PayloadComponentRegistry.php
   - Implement direct event_type to renderer mapping
   - Remove parent-child relationships and aliases

### Key Requirements

1. **Clean Implementation**
   - Plugin hasn't been published yet - no need for backward compatibility
   - Focus on clean, maintainable code
   - Use modern PHP features and best practices

2. **Direct Mapping**
   - Each event_type maps directly to a renderer class
   - No complex lookup system or capability checks
   - Simple, fast renderer selection

3. **UI Toolkit Integration**
   - Use PayloadComponentUIToolkit for all UI rendering
   - Follow UI patterns specified in `event-ui-design.md`
   - Maintain consistent styling across all renderers

4. **Data Handling**
   - Handle all data structures documented in `event-data-structures.md`
   - Implement proper data validation and sanitization
   - Use helper methods for formatting (currency, dates, etc.)

### Implementation Order

1. Create BaseRenderer class with all shared functionality
2. Implement RuleRenderer first (highest priority)
3. Implement remaining renderers in order:
   - PaymentRenderer (high priority)
   - OrderRenderer
   - SystemRenderer
   - AnalysisRenderer
4. Update PayloadComponentRegistry with direct mapping
5. Test each renderer with real event data

## Important Notes

1. **No Legacy Support Needed**
   - This is a pre-release refactor
   - Focus on clean implementation
   - Don't worry about backward compatibility

2. **UI Consistency**
   - Use consistent UI patterns across all renderers
   - Follow the designs in `event-ui-design.md`
   - Use PayloadComponentUIToolkit for all UI generation

3. **Testing**
   - Test each renderer with real event data
   - Verify proper handling of all event types
   - Ensure correct UI generation

## Resources

All necessary documentation is in the .md files mentioned above. The implementation should strictly follow the architecture and patterns described in these documents.

## Expected Outcome

A clean, maintainable renderer system that:
- Directly maps event types to appropriate renderers
- Generates consistent, well-designed UI components
- Handles all event types correctly
- Is easy to extend with new event types
- Has no unnecessary abstraction layers

## Success Criteria

1. All event types render correctly with proper UI
2. Direct mapping from event_type to renderer works
3. UI is consistent across all event types
4. Code is clean and maintainable
5. No unnecessary complexity or abstraction

## Getting Started

1. Read all documentation files thoroughly
2. Start with BaseRenderer implementation
3. Follow implementation order specified above
4. Test each component as you build it

The documentation provides all necessary context - no additional research should be needed. Focus on clean implementation following the specified architecture.
