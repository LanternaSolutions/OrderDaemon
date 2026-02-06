<!-- WHEN Section (Trigger) -->
<div class="odcm-rule-section">
    <h3 class="odcm-section-title">
        WHEN                        <span class="odcm-section-subtitle">(Trigger)</span>
    </h3>

    <div x-show="!rule.trigger" class="odcm-empty-state" style="display: none;">
        <button type="button" @click="isAddingTrigger = !isAddingTrigger" class="odcm-add-component-button odcm-add-trigger-button">
            <span class="odcm-button-icon">+</span>
            admin.rule_builder.action.add_trigger_description                        </button>
    </div>

    <!-- Trigger Component Row -->
    <div x-show="rule.trigger" class="odcm-rule-row odcm-no-settings" :class="{ 'odcm-expanded': editingTriggerIndex === 0, 'odcm-no-settings': !componentHasSettings('trigger', 0), 'odcm-component-inaccessible': !getComponentDefinition('trigger', rule.trigger?.id)?.accessible }" @click="!getComponentDefinition('trigger', rule.trigger?.id)?.accessible ? null : (componentHasSettings('trigger', 0) &amp;&amp; handleRowClick('trigger', 0, $event))">
        <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
        <div class="odcm-component-summary" x-html="getComponentSummary(rule.trigger, 'trigger', 0)"><span class="odcm-summary-title">Order Processing</span></div>
        <div class="odcm-component-actions">
            <button type="button" @click="removeTrigger()" class="odcm-remove-button">
                Remove                            </button>
        </div>
    </div>

    <!-- Trigger Settings Panel -->
    <div x-show="rule.trigger &amp;&amp; editingTriggerIndex === 0" class="odcm-settings-panel" :class="{ 'odcm-expanded': editingTriggerIndex === 0 }" style="display: none;">
        <!-- Trigger Validation Errors -->
        <div x-show="triggerValidationErrors.length &gt; 0" class="odcm-validation-errors" style="display: none;">
            <template x-for="error in triggerValidationErrors" :key="error">
                <div class="odcm-validation-error" x-text="error"></div>
            </template>
        </div>
        <div x-data="settingsPanel('trigger', 0)" x-init="$nextTick(() =&gt; {
                                        const doInit = () =&gt; {
                                            const component = getTriggerComponent(rule.trigger?.id);
                                            const schema = component?.schema;
                                            const settings = rule.trigger?.settings || {};
                                            initSettings(schema, settings);
                                        };
                                        // Initial run
                                        doInit();
                                        // Re-run when the trigger object changes
                                        $watch(() =&gt; rule.trigger, () =&gt; doInit());
                                        // Re-run when the trigger id changes (more granular)
                                        $watch(() =&gt; rule.trigger?.id, () =&gt; doInit());
                                        // Re-run when the panel expands to ensure DOM is ready
                                        $watch(() =&gt; editingTriggerIndex, (v) =&gt; { if (v === 0) doInit(); });
                                    })">
            <template x-if="Object.keys(fields).length &gt; 0">
                <div class="odcm-settings-form">
                    <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                        <div class="odcm-form-group">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'trigger', 0)">
                                                    <div class="odcm-checkbox-content">
                                                        <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                    </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'trigger', 0)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'trigger', 0)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button only -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'trigger', 0)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'trigger', 0)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'trigger', 0)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String(field.value === val)" tabindex="0" @keydown.enter.prevent="$event.currentTarget.querySelector('input')?.click()" @keydown.space.prevent="$event.currentTarget.querySelector('input')?.click()">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.trigger?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'trigger', 0)">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.trigger?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.trigger?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('trigger', field.radioInputs[val], $event.target.value, 'trigger', 0)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.trigger?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.trigger?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'trigger', 0)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'trigger', 0)"></textarea>
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'trigger', 0)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'trigger', 0)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
            <template x-if="Object.keys(fields).length === 0">
                <div class="odcm-no-settings">
                    <p>No configurable settings available for this trigger.</p>
                </div>
            </template><div class="odcm-no-settings">
                    <p>No configurable settings available for this trigger.</p>
                </div>
        </div>
    </div>

    <!-- Trigger Inline Selector -->
    <div x-show="isAddingTrigger" class="odcm-inline-selector" :class="{ 'odcm-expanded': isAddingTrigger }" style="display: none;">
        <div class="odcm-selector-header">
            <input type="text" x-model="triggerSearchTerm" placeholder="admin.rule_builder.search.triggers_placeholder" class="odcm-search-input">
            <button type="button" @click="isAddingTrigger = false" class="odcm-close-selector">×</button>
        </div>
        <div class="odcm-selector-list">
            <template x-for="trigger in filteredTriggers" :key="trigger.id">
                <button type="button" @click="selectComponent('trigger', trigger.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="trigger.label"></div>
                        <div class="odcm-option-description" x-text="trigger.description"></div>
                    </div>
                </button>
            </template><button type="button" @click="selectComponent('trigger', trigger.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="trigger.label">Order Processing</div>
                        <div class="odcm-option-description" x-text="trigger.description">Runs when an order status changes to "Processing". Ideal for most standard automations.</div>
                    </div>
                </button><button type="button" @click="selectComponent('trigger', trigger.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="trigger.label">Order Created</div>
                        <div class="odcm-option-description" x-text="trigger.description">Triggers when a new order is created in the system.</div>
                    </div>
                </button><button type="button" @click="selectComponent('trigger', trigger.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="trigger.label">Payment Complete</div>
                        <div class="odcm-option-description" x-text="trigger.description">Runs when payment is completed, regardless of order status. Ideal for subscription renewals and complex payment workflows.</div>
                    </div>
                </button><button type="button" @click="selectComponent('trigger', trigger.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="trigger.label">Order On-Hold</div>
                        <div class="odcm-option-description" x-text="trigger.description">Runs when an order status changes to "On-Hold". Useful for payment review workflows.</div>
                    </div>
                </button><button type="button" @click="selectComponent('trigger', trigger.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="trigger.label">Any Status Change</div>
                        <div class="odcm-option-description" x-text="trigger.description">Runs when an order status changes to any status. Provides maximum flexibility for complex workflows.</div>
                    </div>
                </button>
        </div>
    </div>
</div>

<!-- IF Section (Conditions) -->
<div class="odcm-rule-section">
    <h3 class="odcm-section-title">
        IF                        <span class="odcm-section-subtitle">(Conditions)</span>
        <span class="odcm-component-count" x-text="`(${rule.conditions.length})`">(5)</span>
    </h3>

    <div x-show="rule.conditions.length === 0" class="odcm-empty-state" style="display: none;">
    </div>

    <!-- Conditions List -->
    <template x-for="(condition, index) in rule.conditions" :key="index">
        <div class="odcm-condition-wrapper">
            <!-- Condition Row -->
            <div class="odcm-rule-row" :class="{ 'odcm-expanded': editingConditionIndex === index, 'odcm-no-settings': !componentHasSettings('condition', index), 'odcm-component-inaccessible': !getComponentDefinition('condition', condition.id)?.accessible }" draggable="true" @dragstart="startDragCondition(index, $event)" @dragover="dragOverCondition(index, $event)" @drop="dropCondition(index, $event)" @dragend="endDrag()" @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) &amp;&amp; handleRowClick('condition', index, $event))">
                <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                <div class="odcm-component-summary" x-html="getComponentSummary(condition, 'condition', index)"></div>
                <div class="odcm-component-actions">
                    <button type="button" @click="removeCondition(index)" class="odcm-remove-button">
                        Remove                                    </button>
                </div>
            </div>

            <!-- Condition Settings Panel -->
            <div x-show="editingConditionIndex === index" class="odcm-settings-panel" :class="{ 'odcm-expanded': editingConditionIndex === index }">
                <div x-data="{
                        ...settingsPanel('condition', index),
                        activeGroup: (rule.conditions[index]?.settings?.comparison_type || getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.default || 'absolute_date'),
                        hasConditionalGroups() {
                            return !!getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
                        },
                        getConditionalGroupFields() {
                            const schema = getConditionComponent(condition.id)?.schema;
                            if (!schema?.properties?.comparison_type?.['ui:conditional_groups']) return null;
                            return schema.properties.comparison_type['ui:conditional_groups'][this.activeGroup] || [];
                        },
                        isFieldInActiveGroup(fieldKey) {
                            if (!this.hasConditionalGroups()) return true;
                            const schema = getConditionComponent(condition.id)?.schema;
                            const conditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];
                            if (!conditionalGroups) return true;
                            // comparison_type is always visible (it's the controller)
                            if (fieldKey === 'comparison_type') return true;
                            // Check if field is in any conditional group
                            const allConditionalFields = Object.values(conditionalGroups).flat();
                            if (!allConditionalFields.includes(fieldKey)) return true;
                            // Check if field is in the active group
                            const activeFields = conditionalGroups[this.activeGroup] || [];
                            return activeFields.includes(fieldKey);
                        }
                        }" x-init="initSettings(getConditionComponent(condition.id)?.schema, condition.settings || {});
                                $watch('rule.conditions[' + index + '].settings.comparison_type', (val) =&gt; { if (val) activeGroup = val; })">
                    <div class="odcm-settings-form">
                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                            <div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </template>
                    </div>
                </div>
            </div>
        </div>
    </template><div class="odcm-condition-wrapper">
            <!-- Condition Row -->
            <div class="odcm-rule-row" :class="{ 'odcm-expanded': editingConditionIndex === index, 'odcm-no-settings': !componentHasSettings('condition', index), 'odcm-component-inaccessible': !getComponentDefinition('condition', condition.id)?.accessible }" draggable="true" @dragstart="startDragCondition(index, $event)" @dragover="dragOverCondition(index, $event)" @drop="dropCondition(index, $event)" @dragend="endDrag()" @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) &amp;&amp; handleRowClick('condition', index, $event))">
                <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                <div class="odcm-component-summary" x-html="getComponentSummary(condition, 'condition', index)"><span class="odcm-summary-title">Source Gateway</span></div>
                <div class="odcm-component-actions">
                    <button type="button" @click="removeCondition(index)" class="odcm-remove-button">
                        Remove                                    </button>
                </div>
            </div>

            <!-- Condition Settings Panel -->
            <div x-show="editingConditionIndex === index" class="odcm-settings-panel" :class="{ 'odcm-expanded': editingConditionIndex === index }" style="display: none;">
                <div x-data="{
                        ...settingsPanel('condition', index),
                        activeGroup: (rule.conditions[index]?.settings?.comparison_type || getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.default || 'absolute_date'),
                        hasConditionalGroups() {
                            return !!getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
                        },
                        getConditionalGroupFields() {
                            const schema = getConditionComponent(condition.id)?.schema;
                            if (!schema?.properties?.comparison_type?.['ui:conditional_groups']) return null;
                            return schema.properties.comparison_type['ui:conditional_groups'][this.activeGroup] || [];
                        },
                        isFieldInActiveGroup(fieldKey) {
                            if (!this.hasConditionalGroups()) return true;
                            const schema = getConditionComponent(condition.id)?.schema;
                            const conditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];
                            if (!conditionalGroups) return true;
                            // comparison_type is always visible (it's the controller)
                            if (fieldKey === 'comparison_type') return true;
                            // Check if field is in any conditional group
                            const allConditionalFields = Object.values(conditionalGroups).flat();
                            if (!allConditionalFields.includes(fieldKey)) return true;
                            // Check if field is in the active group
                            const activeFields = conditionalGroups[this.activeGroup] || [];
                            return activeFields.includes(fieldKey);
                        }
                        }" x-init="initSettings(getConditionComponent(condition.id)?.schema, condition.settings || {});
                                $watch('rule.conditions[' + index + '].settings.comparison_type', (val) =&gt; { if (val) activeGroup = val; })">
                    <div class="odcm-settings-form">
                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                            <div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </template><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_0_gateways">Payment Gateways</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">Select payment gateways to match.</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template><template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template><div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()" id="condition_0_gateways_search" placeholder="Search options...">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()" style="display: none;">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_0_gateways_paypal">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_0_gateways_paypal" value="paypal">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">PayPal</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_0_gateways_stripe">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_0_gateways_stripe" value="stripe">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Stripe</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_0_gateways_cod">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_0_gateways_cod" value="cod">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Cash on Delivery</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_0_gateways_bacs">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_0_gateways_bacs" value="bacs">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Direct Bank Transfer</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_0_gateways_cheque">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_0_gateways_cheque" value="cheque">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Check Payments</span>
                                                </div>
                                                </label>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results" style="display: none;">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0" style="display: none;">
                                            Selected: <span x-text="selectedValues.length">0</span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)" style="display: none;">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_0_match_mode">Match Mode</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">How to match the selected gateways.</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_0_match_mode_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_0_match_mode_any" aria-checked="true">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_0_match_mode_any" name="condition_0_match_mode" value="any" checked="checked">
                                            <span class="odcm-radio-text" x-text="label">Match any selected gateway</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_0_match_mode_none" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_0_match_mode_none" name="condition_0_match_mode" value="none">
                                            <span class="odcm-radio-text" x-text="label">Match if not any of the selected gateways</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div><div class="odcm-condition-wrapper">
            <!-- Condition Row -->
            <div class="odcm-rule-row" :class="{ 'odcm-expanded': editingConditionIndex === index, 'odcm-no-settings': !componentHasSettings('condition', index), 'odcm-component-inaccessible': !getComponentDefinition('condition', condition.id)?.accessible }" draggable="true" @dragstart="startDragCondition(index, $event)" @dragover="dragOverCondition(index, $event)" @drop="dropCondition(index, $event)" @dragend="endDrag()" @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) &amp;&amp; handleRowClick('condition', index, $event))">
                <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                <div class="odcm-component-summary" x-html="getComponentSummary(condition, 'condition', index)"><span class="odcm-summary-title">Event Type</span></div>
                <div class="odcm-component-actions">
                    <button type="button" @click="removeCondition(index)" class="odcm-remove-button">
                        Remove                                    </button>
                </div>
            </div>

            <!-- Condition Settings Panel -->
            <div x-show="editingConditionIndex === index" class="odcm-settings-panel" :class="{ 'odcm-expanded': editingConditionIndex === index }" style="display: none;">
                <div x-data="{
                        ...settingsPanel('condition', index),
                        activeGroup: (rule.conditions[index]?.settings?.comparison_type || getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.default || 'absolute_date'),
                        hasConditionalGroups() {
                            return !!getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
                        },
                        getConditionalGroupFields() {
                            const schema = getConditionComponent(condition.id)?.schema;
                            if (!schema?.properties?.comparison_type?.['ui:conditional_groups']) return null;
                            return schema.properties.comparison_type['ui:conditional_groups'][this.activeGroup] || [];
                        },
                        isFieldInActiveGroup(fieldKey) {
                            if (!this.hasConditionalGroups()) return true;
                            const schema = getConditionComponent(condition.id)?.schema;
                            const conditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];
                            if (!conditionalGroups) return true;
                            // comparison_type is always visible (it's the controller)
                            if (fieldKey === 'comparison_type') return true;
                            // Check if field is in any conditional group
                            const allConditionalFields = Object.values(conditionalGroups).flat();
                            if (!allConditionalFields.includes(fieldKey)) return true;
                            // Check if field is in the active group
                            const activeFields = conditionalGroups[this.activeGroup] || [];
                            return activeFields.includes(fieldKey);
                        }
                        }" x-init="initSettings(getConditionComponent(condition.id)?.schema, condition.settings || {});
                                $watch('rule.conditions[' + index + '].settings.comparison_type', (val) =&gt; { if (val) activeGroup = val; })">
                    <div class="odcm-settings-form">
                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                            <div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </template><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_1_event_types">Event Types</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">Select event types to match.</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template><template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template><div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()" id="condition_1_event_types_search" placeholder="Search options...">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()" style="display: none;">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_payment_completed">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_payment_completed" value="payment_completed">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Payment Completed</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_subscription_cancelled">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_subscription_cancelled" value="subscription_cancelled">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Subscription Cancelled</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_subscription_created">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_subscription_created" value="subscription_created">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Subscription Created</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_subscription_renewed">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_subscription_renewed" value="subscription_renewed">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Subscription Renewed</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_refund_processed">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_refund_processed" value="refund_processed">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Refund Processed</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_order_status_changed">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_order_status_changed" value="order_status_changed">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Order Status Changed</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_checkout_initiated">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_checkout_initiated" value="checkout_initiated">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Checkout Initiated</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_1_event_types_customer_created">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_1_event_types_customer_created" value="customer_created">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Customer Created</span>
                                                </div>
                                                </label>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results" style="display: none;">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0" style="display: none;">
                                            Selected: <span x-text="selectedValues.length">0</span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)" style="display: none;">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_1_match_mode">Match Mode</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">How to match the selected event types.</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_1_match_mode_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_1_match_mode_any" aria-checked="true">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_1_match_mode_any" name="condition_1_match_mode" value="any" checked="checked">
                                            <span class="odcm-radio-text" x-text="label">Match any selected event type</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_1_match_mode_all" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_1_match_mode_all" name="condition_1_match_mode" value="all">
                                            <span class="odcm-radio-text" x-text="label">Match all selected event types</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_1_match_mode_none" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_1_match_mode_none" name="condition_1_match_mode" value="none">
                                            <span class="odcm-radio-text" x-text="label">Match if not any of the selected event types</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div><div class="odcm-condition-wrapper">
            <!-- Condition Row -->
            <div class="odcm-rule-row" :class="{ 'odcm-expanded': editingConditionIndex === index, 'odcm-no-settings': !componentHasSettings('condition', index), 'odcm-component-inaccessible': !getComponentDefinition('condition', condition.id)?.accessible }" draggable="true" @dragstart="startDragCondition(index, $event)" @dragover="dragOverCondition(index, $event)" @drop="dropCondition(index, $event)" @dragend="endDrag()" @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) &amp;&amp; handleRowClick('condition', index, $event))">
                <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                <div class="odcm-component-summary" x-html="getComponentSummary(condition, 'condition', index)"><span class="odcm-summary-title">Order Total</span>: <span class="odcm-summary-operator">≥</span> <span class="odcm-summary-values">$0</span></div>
                <div class="odcm-component-actions">
                    <button type="button" @click="removeCondition(index)" class="odcm-remove-button">
                        Remove                                    </button>
                </div>
            </div>

            <!-- Condition Settings Panel -->
            <div x-show="editingConditionIndex === index" class="odcm-settings-panel" :class="{ 'odcm-expanded': editingConditionIndex === index }" style="display: none;">
                <div x-data="{
                        ...settingsPanel('condition', index),
                        activeGroup: (rule.conditions[index]?.settings?.comparison_type || getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.default || 'absolute_date'),
                        hasConditionalGroups() {
                            return !!getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
                        },
                        getConditionalGroupFields() {
                            const schema = getConditionComponent(condition.id)?.schema;
                            if (!schema?.properties?.comparison_type?.['ui:conditional_groups']) return null;
                            return schema.properties.comparison_type['ui:conditional_groups'][this.activeGroup] || [];
                        },
                        isFieldInActiveGroup(fieldKey) {
                            if (!this.hasConditionalGroups()) return true;
                            const schema = getConditionComponent(condition.id)?.schema;
                            const conditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];
                            if (!conditionalGroups) return true;
                            // comparison_type is always visible (it's the controller)
                            if (fieldKey === 'comparison_type') return true;
                            // Check if field is in any conditional group
                            const allConditionalFields = Object.values(conditionalGroups).flat();
                            if (!allConditionalFields.includes(fieldKey)) return true;
                            // Check if field is in the active group
                            const activeFields = conditionalGroups[this.activeGroup] || [];
                            return activeFields.includes(fieldKey);
                        }
                        }" x-init="initSettings(getConditionComponent(condition.id)?.schema, condition.settings || {});
                                $watch('rule.conditions[' + index + '].settings.comparison_type', (val) =&gt; { if (val) activeGroup = val; })">
                    <div class="odcm-settings-form">
                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                            <div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </template><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_date_field">Date Field</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">Choose which kind of date to use</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template><div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_2_date_field_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template><button type="button" class="odcm-radio-button is-active" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="true">Payment Date</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Order Date</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Due Date</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Scheduled Date</button>
                                </div>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_comparison_type">Comparison Type</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">Choose how to compare the date</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template><div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_2_comparison_type_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template><button type="button" class="odcm-radio-button is-active" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="true">Absolute Date</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Relative Time</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Date Range</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Time of Day</button>
                                </div>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_comparison_operator">is</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_2_comparison_operator_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_comparison_operator_equals" aria-checked="true">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_comparison_operator_equals" name="condition_2_comparison_operator" value="equals" checked="checked">
                                            <span class="odcm-radio-text" x-text="label">Equals</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_comparison_operator_not_equals" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_comparison_operator_not_equals" name="condition_2_comparison_operator" value="not_equals">
                                            <span class="odcm-radio-text" x-text="label">Not Equals</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_comparison_operator_after" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_comparison_operator_after" name="condition_2_comparison_operator" value="after">
                                            <span class="odcm-radio-text" x-text="label">After</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_comparison_operator_on_or_after" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_comparison_operator_on_or_after" name="condition_2_comparison_operator" value="on_or_after">
                                            <span class="odcm-radio-text" x-text="label">On or After</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_comparison_operator_before" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_comparison_operator_before" name="condition_2_comparison_operator" value="before">
                                            <span class="odcm-radio-text" x-text="label">Before</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_comparison_operator_on_or_before" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_comparison_operator_on_or_before" name="condition_2_comparison_operator" value="on_or_before">
                                            <span class="odcm-radio-text" x-text="label">On or Before</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_comparison_date">Date</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template><input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)" id="condition_2_comparison_date">

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_relative_operator">is</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_2_relative_operator_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_relative_operator_more_than" aria-checked="true">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_relative_operator_more_than" name="condition_2_relative_operator" value="more_than" checked="checked">
                                            <span class="odcm-radio-text" x-text="label">More Than</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_relative_operator_less_than" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_relative_operator_less_than" name="condition_2_relative_operator" value="less_than">
                                            <span class="odcm-radio-text" x-text="label">Less Than</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_time_period">Amount</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template><input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)" id="condition_2_time_period" min="1" step="1">

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_time_unit">Unit</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_2_time_unit_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_time_unit_days" aria-checked="true">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_time_unit_days" name="condition_2_time_unit" value="days" checked="checked">
                                            <span class="odcm-radio-text" x-text="label">Days Ago</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_time_unit_hours" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_time_unit_hours" name="condition_2_time_unit" value="hours">
                                            <span class="odcm-radio-text" x-text="label">Hours Ago</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_2_time_unit_minutes" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_2_time_unit_minutes" name="condition_2_time_unit" value="minutes">
                                            <span class="odcm-radio-text" x-text="label">Minutes Ago</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_range_operator">is</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template><div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_2_range_operator_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template><button type="button" class="odcm-radio-button is-active" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="true">Within Range</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Outside Range</button>
                                </div>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_range_start_date">From</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template><input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)" id="condition_2_range_start_date">

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_range_end_date">To</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template><input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)" id="condition_2_range_end_date">

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_time_comparison_type">is</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description" style="display: none;"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template><div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_2_time_comparison_type_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template><button type="button" class="odcm-radio-button is-active" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="true">Within Range</button><button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label" aria-pressed="false">Outside Range</button>
                                </div>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_time_range_start">From</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">24-hour format, according to website timezone</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template><input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)" id="condition_2_time_range_start">

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_2_time_range_end">To</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">24-hour format, according to website timezone</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template><input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)" id="condition_2_time_range_end">

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div><div class="odcm-condition-wrapper">
            <!-- Condition Row -->
            <div class="odcm-rule-row" :class="{ 'odcm-expanded': editingConditionIndex === index, 'odcm-no-settings': !componentHasSettings('condition', index), 'odcm-component-inaccessible': !getComponentDefinition('condition', condition.id)?.accessible }" draggable="true" @dragstart="startDragCondition(index, $event)" @dragover="dragOverCondition(index, $event)" @drop="dropCondition(index, $event)" @dragend="endDrag()" @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) &amp;&amp; handleRowClick('condition', index, $event))">
                <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                <div class="odcm-component-summary" x-html="getComponentSummary(condition, 'condition', index)"><span class="odcm-summary-title">Product Category</span>: <span class="odcm-summary-values">Any category</span></div>
                <div class="odcm-component-actions">
                    <button type="button" @click="removeCondition(index)" class="odcm-remove-button">
                        Remove                                    </button>
                </div>
            </div>

            <!-- Condition Settings Panel -->
            <div x-show="editingConditionIndex === index" class="odcm-settings-panel" :class="{ 'odcm-expanded': editingConditionIndex === index }" style="display: none;">
                <div x-data="{
                        ...settingsPanel('condition', index),
                        activeGroup: (rule.conditions[index]?.settings?.comparison_type || getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.default || 'absolute_date'),
                        hasConditionalGroups() {
                            return !!getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
                        },
                        getConditionalGroupFields() {
                            const schema = getConditionComponent(condition.id)?.schema;
                            if (!schema?.properties?.comparison_type?.['ui:conditional_groups']) return null;
                            return schema.properties.comparison_type['ui:conditional_groups'][this.activeGroup] || [];
                        },
                        isFieldInActiveGroup(fieldKey) {
                            if (!this.hasConditionalGroups()) return true;
                            const schema = getConditionComponent(condition.id)?.schema;
                            const conditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];
                            if (!conditionalGroups) return true;
                            // comparison_type is always visible (it's the controller)
                            if (fieldKey === 'comparison_type') return true;
                            // Check if field is in any conditional group
                            const allConditionalFields = Object.values(conditionalGroups).flat();
                            if (!allConditionalFields.includes(fieldKey)) return true;
                            // Check if field is in the active group
                            const activeFields = conditionalGroups[this.activeGroup] || [];
                            return activeFields.includes(fieldKey);
                        }
                        }" x-init="initSettings(getConditionComponent(condition.id)?.schema, condition.settings || {});
                                $watch('rule.conditions[' + index + '].settings.comparison_type', (val) =&gt; { if (val) activeGroup = val; })">
                    <div class="odcm-settings-form">
                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                            <div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </template><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_3_operator">rule_component.condition.product_category.operator_label</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">rule_component.condition.product_category.operator_description</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_3_operator_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_3_operator_in" aria-checked="true">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_3_operator_in" name="condition_3_operator" value="in" checked="checked">
                                            <span class="odcm-radio-text" x-text="label">rule_component.condition.product_category.operator.in</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_3_operator_not_in" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_3_operator_not_in" name="condition_3_operator" value="not_in">
                                            <span class="odcm-radio-text" x-text="label">rule_component.condition.product_category.operator.not_in</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_3_operator_all_in" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_3_operator_all_in" name="condition_3_operator" value="all_in">
                                            <span class="odcm-radio-text" x-text="label">rule_component.condition.product_category.operator.all_in</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_3_category">Product Category</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">Select one product category to match. Pro unlocks multiple categories and advanced logic.</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_3_category_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_3_category_15" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_3_category_15" name="condition_3_category" value="15">
                                            <span class="odcm-radio-text" x-text="label">Uncategorized</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_3_category_16" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_3_category_16" name="condition_3_category" value="16">
                                            <span class="odcm-radio-text" x-text="label">Courses</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div><div class="odcm-condition-wrapper">
            <!-- Condition Row -->
            <div class="odcm-rule-row" :class="{ 'odcm-expanded': editingConditionIndex === index, 'odcm-no-settings': !componentHasSettings('condition', index), 'odcm-component-inaccessible': !getComponentDefinition('condition', condition.id)?.accessible }" draggable="true" @dragstart="startDragCondition(index, $event)" @dragover="dragOverCondition(index, $event)" @drop="dropCondition(index, $event)" @dragend="endDrag()" @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) &amp;&amp; handleRowClick('condition', index, $event))">
                <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                <div class="odcm-component-summary" x-html="getComponentSummary(condition, 'condition', index)"><span class="odcm-summary-title">Product Type</span>: <span class="odcm-summary-values">none selected</span> <span class="odcm-summary-match">(All match)</span></div>
                <div class="odcm-component-actions">
                    <button type="button" @click="removeCondition(index)" class="odcm-remove-button">
                        Remove                                    </button>
                </div>
            </div>

            <!-- Condition Settings Panel -->
            <div x-show="editingConditionIndex === index" class="odcm-settings-panel" :class="{ 'odcm-expanded': editingConditionIndex === index }" style="display: none;">
                <div x-data="{
                        ...settingsPanel('condition', index),
                        activeGroup: (rule.conditions[index]?.settings?.comparison_type || getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.default || 'absolute_date'),
                        hasConditionalGroups() {
                            return !!getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
                        },
                        getConditionalGroupFields() {
                            const schema = getConditionComponent(condition.id)?.schema;
                            if (!schema?.properties?.comparison_type?.['ui:conditional_groups']) return null;
                            return schema.properties.comparison_type['ui:conditional_groups'][this.activeGroup] || [];
                        },
                        isFieldInActiveGroup(fieldKey) {
                            if (!this.hasConditionalGroups()) return true;
                            const schema = getConditionComponent(condition.id)?.schema;
                            const conditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];
                            if (!conditionalGroups) return true;
                            // comparison_type is always visible (it's the controller)
                            if (fieldKey === 'comparison_type') return true;
                            // Check if field is in any conditional group
                            const allConditionalFields = Object.values(conditionalGroups).flat();
                            if (!allConditionalFields.includes(fieldKey)) return true;
                            // Check if field is in the active group
                            const activeFields = conditionalGroups[this.activeGroup] || [];
                            return activeFields.includes(fieldKey);
                        }
                        }" x-init="initSettings(getConditionComponent(condition.id)?.schema, condition.settings || {});
                                $watch('rule.conditions[' + index + '].settings.comparison_type', (val) =&gt; { if (val) activeGroup = val; })">
                    <div class="odcm-settings-form">
                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                            <div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </template><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_4_types">Product Types</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">Select the product types to match. Use the search box to quickly find product types.</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template><template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template><div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()" id="condition_4_types_search" placeholder="Search product types...">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()" style="display: none;">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label is-selected" for="condition_4_types_virtual">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_4_types_virtual" value="virtual" checked="checked">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Virtual Products</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label is-selected" for="condition_4_types_downloadable">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_4_types_downloadable" value="downloadable" checked="checked">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Downloadable Products</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_4_types_simple">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_4_types_simple" value="simple">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Simple product</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_4_types_grouped">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_4_types_grouped" value="grouped">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Grouped product</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_4_types_external">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_4_types_external" value="external">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">External/Affiliate product</span>
                                                </div>
                                                </label><label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)" class="odcm-checkbox-label" for="condition_4_types_variable">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)" id="condition_4_types_variable" value="variable">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label">Variable product</span>
                                                </div>
                                                </label>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results" style="display: none;">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length">2</span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div><div class="odcm-form-group" x-show="isFieldInActiveGroup(fieldKey)" :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                            <!-- Field Label -->
                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title" for="condition_4_match_mode">Match Mode</label>

                            <!-- Field Description -->
                            <div x-show="field.description" class="odcm-form-description" x-text="field.description">How to match product types in the order.</div>

                            <!-- Searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'searchable_checkboxes'">
                                <template x-if="field.enumOptions &amp;&amp; Object.keys(field.enumOptions).length &gt; 0">
                                    <div class="odcm-searchable-checkboxes" x-data="searchableWidget(field.id)" x-init="$nextTick(() =&gt; init(field.enumOptions, field.selectedValues, field.key))">
                                    <div class="odcm-search-header">
                                        <input type="text" :id="field.id + '_search'" class="odcm-search-input" :placeholder="field.placeholder || 'Search options...'" x-model="searchTerm" @input="filterOptions()">
                                        <button type="button" class="odcm-show-all-button" x-show="searchTerm &amp;&amp; !showAll" @click="showAll = true; filterOptions()">
                                            Show All
                                        </button>
                                    </div>
                                    <div class="odcm-searchable-list">
                                        <div class="odcm-checkbox-group">
                                            <template x-for="option in filteredOptions" :key="option.value">
                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                    <input type="checkbox" :id="field.id + '_' + option.value" :value="option.value" :checked="selectedValues.includes(option.value)" :disabled="shouldDisableOption(option.value)" @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                    <div class="odcm-checkbox-content">
                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                </div>
                                                </label>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                <p>No options found matching your search.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="odcm-selected-summary">
                                        <span class="odcm-summary-text" x-show="selectedValues.length &gt; 0">
                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                        </span>
                                        <div class="odcm-selected-summary-buttons">
                                            <button type="button" class="odcm-select-all-compact" x-show="canSelectAll &amp;&amp; hasSelectableOptions" @click="selectAll(field.key, 'condition', index)">
                                                Select All
                                            </button>
                                            <button type="button" class="odcm-clear-all-compact" x-show="selectedValues.length &gt; 0" @click="clearAll(field.key, 'condition', index)">
                                                Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </template>

                            <!-- Non-searchable Checkboxes Widget -->
                            <template x-if="field.widget === 'checkboxes'">
                                <div class="odcm-checkbox-group">
                                    <!-- Clear All button -->
                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                        <button type="button" class="odcm-clear-all-button odcm-checkbox-control-button" @click="clearAllCheckboxes(field.key, 'condition', index)">
                                            Clear All
                                        </button>
                                    </div>
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                            <input type="checkbox" :id="field.id + '_' + val" :value="val" :checked="(field.selectedValues || []).includes(val)" @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>

                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                            <template x-if="field.widget === 'radio_group'">
                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template><div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'" aria-labelledby="condition_4_match_mode_label">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
                                            <span class="odcm-radio-text" x-text="label"></span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                    </template><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_4_match_mode_all" aria-checked="true">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_4_match_mode_all" name="condition_4_match_mode" value="all" checked="checked">
                                            <span class="odcm-radio-text" x-text="label">All products must match selected types</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_4_match_mode_any" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_4_match_mode_any" name="condition_4_match_mode" value="any">
                                            <span class="odcm-radio-text" x-text="label">At least one product must match selected types</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label><label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0" for="condition_4_match_mode_none" aria-checked="false">
                                            <input type="radio" :id="field.id + '_' + val" :name="field.id" :value="val" :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val" @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value" id="condition_4_match_mode_none" name="condition_4_match_mode" value="none">
                                            <span class="odcm-radio-text" x-text="label">No products should match selected types</span>
                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                            <template x-if="field.radioInputs &amp;&amp; field.radioInputs[val]">
                                                <input type="number" class="odcm-inline-number-input" :min="field.minimum !== null ? field.minimum : undefined" :max="field.maximum !== null ? field.maximum : undefined" :step="field.step !== null ? field.step : undefined" :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')" :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val" @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                            </template>
                                        </label>
                                </div>

                            <!-- Button-style radio group -->
                            <template x-if="field.widget === 'button_radio_group'">
                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                        <button type="button" class="odcm-radio-button" :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }" :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" @click="updateSetting(field.key, val, 'condition', index)" x-text="label">
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- Textarea field -->
                            <template x-if="field.widget === 'textarea'">
                                <textarea :id="field.id" class="odcm-form-textarea" rows="6" :placeholder="field.placeholder || ''" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                            </template>

                            <!-- Date picker widget -->
                            <template x-if="field.widget === 'date_picker'">
                                <input type="date" :id="field.id" class="odcm-form-input odcm-date-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Time picker widget -->
                            <template x-if="field.widget === 'time_picker'">
                                <input type="time" :id="field.id" class="odcm-form-input odcm-time-picker" :value="field.value" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Number input widget -->
                            <template x-if="field.widget === 'number'">
                                <input type="number" :id="field.id" class="odcm-form-input odcm-number-input" :value="field.value" :min="field.minimum" :max="field.maximum" :step="field.step" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <!-- Other field types -->
                            <template x-if="field.widget === 'text'">
                                <input type="text" :id="field.id" class="odcm-form-input" :value="field.value" :placeholder="field.placeholder || ''" @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                            </template>

                            <template x-if="field.widget === 'checkbox'">
                                <label class="odcm-checkbox-label">
                                    <input type="checkbox" :id="field.id" :checked="field.value" @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Add Condition Button -->
    <button type="button" @click="isAddingCondition = !isAddingCondition" class="odcm-add-component-button odcm-add-condition-button">
        <span class="odcm-button-icon">+</span>
            Add Condition                    </button>

    <!-- Condition Inline Selector -->
    <div x-show="isAddingCondition" class="odcm-inline-selector" :class="{ 'odcm-expanded': isAddingCondition }" style="display: none;">
        <div class="odcm-selector-header">
            <input type="text" x-model="conditionSearchTerm" placeholder="Search conditions..." class="odcm-search-input">
            <button type="button" @click="isAddingCondition = false" class="odcm-close-selector">×</button>
        </div>
        <div class="odcm-selector-list">
            <template x-for="condition in filteredConditions" :key="condition.id">
                <button type="button" @click="selectComponent('condition', condition.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="condition.label"></div>
                        <div class="odcm-option-description" x-text="condition.description"></div>
                    </div>
                </button>
            </template><button type="button" @click="selectComponent('condition', condition.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="condition.label">Source Gateway</div>
                        <div class="odcm-option-description" x-text="condition.description">Check if the universal event originates from specific payment gateways (e.g., PayPal, Stripe).</div>
                    </div>
                </button><button type="button" @click="selectComponent('condition', condition.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="condition.label">Event Type</div>
                        <div class="odcm-option-description" x-text="condition.description">Check if the universal event matches specific event types (e.g., payment_completed, subscription_cancelled).</div>
                    </div>
                </button><button type="button" @click="selectComponent('condition', condition.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="condition.label">Timing Condition</div>
                        <div class="odcm-option-description" x-text="condition.description">Check order timing with conditional field rendering</div>
                    </div>
                </button><button type="button" @click="selectComponent('condition', condition.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="condition.label">Order Total Amount</div>
                        <div class="odcm-option-description" x-text="condition.description">Checks if the order total meets the specified criteria.</div>
                    </div>
                </button><button type="button" @click="selectComponent('condition', condition.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="condition.label">Product Category</div>
                        <div class="odcm-option-description" x-text="condition.description">Checks if the order contains products from specific categories.</div>
                    </div>
                </button><button type="button" @click="selectComponent('condition', condition.id)" class="odcm-selector-option">
                    <div class="odcm-option-content">
                        <div class="odcm-option-title" x-text="condition.label">Product Type</div>
                        <div class="odcm-option-description" x-text="condition.description">Checks if all products in the order are of specific types. Virtual and downloadable products are available in the free version.</div>
                    </div>
                </button>
        </div>
    </div>
</div>
