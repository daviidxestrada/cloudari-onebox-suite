document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('cloudari-integrations');
  const template = document.getElementById('cloudari-integration-template');
  const addBtn = document.getElementById('cloudari-add-integration');
  const venueOrderToggle = document.getElementById('enable_venue_display_order');
  const venueOrderWrap = document.querySelector('[data-cloudari-sortable-venue-wrap]');
  const venueOrderList = document.querySelector('[data-cloudari-sortable-venue-list]');
  const styleConfig = window.cloudariOneboxAdminStyleConfig || {
    globalFields: {},
    sections: {},
  };

  function toggleSecret(btn) {
    const root = btn.closest('[data-integration]');
    if (!root) return;
    const input = root.querySelector('input[data-secret-input]');
    if (!input) return;

    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.textContent = isPassword ? 'Ocultar' : 'Mostrar';
    btn.setAttribute(
      'aria-label',
      isPassword ? 'Ocultar client secret' : 'Mostrar client secret'
    );
  }

  function ensureDefaultChecked() {
    if (!container) return;
    const checked = container.querySelector('input[name="default_integration"]:checked');
    if (!checked) {
      const first = container.querySelector('input[name="default_integration"]');
      if (first) first.checked = true;
    }
  }

  function trimValue(value) {
    return typeof value === 'string' ? value.trim() : '';
  }

  function getInputById(inputId) {
    return inputId ? document.getElementById(inputId) : null;
  }

  function getGlobalDefinition(key) {
    return styleConfig.globalFields && styleConfig.globalFields[key]
      ? styleConfig.globalFields[key]
      : null;
  }

  function getSectionDefinition(sectionKey) {
    return styleConfig.sections && styleConfig.sections[sectionKey]
      ? styleConfig.sections[sectionKey]
      : null;
  }

  function resolveStepValue(step) {
    if (!step || typeof step !== 'object') return '';

    if (step.type === 'global') {
      return getEffectiveGlobalValue(step.key);
    }

    return trimValue(step.value);
  }

  function resolveSteps(steps) {
    if (!Array.isArray(steps)) return '';

    for (const step of steps) {
      const value = resolveStepValue(step);
      if (value !== '') {
        return value;
      }
    }

    return '';
  }

  function getEffectiveGlobalValue(key) {
    const definition = getGlobalDefinition(key);
    if (!definition) return '';

    const input = getInputById(definition.inputId);
    const rawValue = trimValue(input ? input.value : '');
    if (rawValue !== '') {
      return rawValue;
    }

    if (Array.isArray(definition.resolve) && definition.resolve.length > 0) {
      return resolveSteps(definition.resolve);
    }

    return trimValue(definition.default);
  }

  function getInheritText(steps) {
    if (!Array.isArray(steps) || steps.length === 0 || typeof steps[0] !== 'object') {
      return '';
    }

    const first = steps[0];
    const resolvedValue = resolveStepValue(first);

    if (first.type === 'global') {
      const label = trimValue(first.label) || trimValue(first.key);
      if (label === '') {
        return resolvedValue;
      }

      return resolvedValue !== '' ? `${label} (${resolvedValue})` : label;
    }

    return resolvedValue;
  }

  function extractPreviewColor(value) {
    const trimmed = trimValue(value);
    if (trimmed === '') return '';

    const hexMatch = trimmed.match(/#[0-9a-fA-F]{3,6}\b/);
    if (hexMatch) {
      return hexMatch[0].toUpperCase();
    }

    if (trimmed.toLowerCase().includes('transpar')) {
      return 'transparent';
    }

    return '';
  }

  function updateChip(chip, previewColor) {
    if (!chip) return;

    if (previewColor === '') {
      chip.hidden = true;
      chip.style.removeProperty('--cloudari-chip-color');
      return;
    }

    chip.hidden = false;
    chip.style.setProperty('--cloudari-chip-color', previewColor);
  }

  function updateFieldPresentation(input, currentValue, inheritText, placeholderValue) {
    if (!input) return;

    const cell = input.closest('td');
    if (!cell) return;

    const currentCode = cell.querySelector('[data-cloudari-style-current-code]');
    if (currentCode) {
      currentCode.textContent = currentValue;
    }

    const inheritNode = cell.querySelector('[data-cloudari-style-inherit]');
    if (inheritNode && inheritText !== null) {
      inheritNode.textContent = inheritText !== '' ? `Vacio = hereda ${inheritText}` : '';
    }

    if (typeof placeholderValue === 'string') {
      input.placeholder = placeholderValue;
    }

    updateChip(cell.querySelector('[data-cloudari-style-chip]'), extractPreviewColor(currentValue));
  }

  function updateGlobalField(key) {
    const definition = getGlobalDefinition(key);
    if (!definition) return;

    const input = getInputById(definition.inputId);
    if (!input) return;

    updateFieldPresentation(input, getEffectiveGlobalValue(key), null, input.placeholder);
  }

  function updateLegacyField(sectionKey, fieldKey) {
    const section = getSectionDefinition(sectionKey);
    const definition = section && section.legacyFields ? section.legacyFields[fieldKey] : null;
    if (!definition) return;

    const input = getInputById(definition.inputId);
    if (!input) return;

    const rawValue = trimValue(input.value);
    const inheritText = getInheritText(definition.resolve);
    const resolvedValue = resolveSteps(definition.resolve);
    const currentValue = rawValue !== '' ? rawValue : resolvedValue;

    updateFieldPresentation(input, currentValue, inheritText, resolvedValue);
  }

  function updateWidgetField(sectionKey, fieldKey) {
    const section = getSectionDefinition(sectionKey);
    const definition = section && section.fields ? section.fields[fieldKey] : null;
    if (!definition) return;

    const input = getInputById(definition.inputId);
    if (!input) return;

    const rawValue = trimValue(input.value);
    const inheritText = getInheritText(definition.resolve);
    const resolvedValue = resolveSteps(definition.resolve);
    const currentValue = rawValue !== '' ? rawValue : resolvedValue;

    updateFieldPresentation(input, currentValue, inheritText, resolvedValue);
  }

  function refreshStylePreview() {
    Object.keys(styleConfig.globalFields || {}).forEach(updateGlobalField);

    Object.entries(styleConfig.sections || {}).forEach(([sectionKey, section]) => {
      Object.keys(section.legacyFields || {}).forEach((fieldKey) => {
        updateLegacyField(sectionKey, fieldKey);
      });

      Object.keys(section.fields || {}).forEach((fieldKey) => {
        updateWidgetField(sectionKey, fieldKey);
      });
    });
  }

  function setInputValue(input, value) {
    if (!input) return;
    input.value = value;
  }

  function resetGlobalStyles() {
    Object.entries(styleConfig.globalFields || {}).forEach(([fieldKey, definition]) => {
      const input = getInputById(definition.inputId);
      if (!input) return;

      if (fieldKey === 'color_selected_day') {
        setInputValue(input, '');
        return;
      }

      setInputValue(input, trimValue(definition.default));
    });
  }

  function resetWidgetSection(sectionKey) {
    const section = getSectionDefinition(sectionKey);
    if (!section) return;

    Object.values(section.legacyFields || {}).forEach((definition) => {
      setInputValue(getInputById(definition.inputId), trimValue(definition.resetValue));
    });

    Object.values(section.fields || {}).forEach((definition) => {
      setInputValue(getInputById(definition.inputId), trimValue(definition.resetValue));
    });
  }

  function resetAllWidgetSections() {
    Object.keys(styleConfig.sections || {}).forEach(resetWidgetSection);
  }

  function getVenueOrderItems() {
    return venueOrderList
      ? Array.from(venueOrderList.querySelectorAll('[data-cloudari-sortable-venue-item]'))
      : [];
  }

  function updateVenueOrderIndices() {
    getVenueOrderItems().forEach((item, index) => {
      const indexEl = item.querySelector('[data-cloudari-sortable-venue-index]');
      if (indexEl) {
        indexEl.textContent = String(index + 1);
      }
    });
  }

  function isVenueOrderEnabled() {
    return !venueOrderToggle || venueOrderToggle.checked;
  }

  function updateVenueOrderState() {
    if (!venueOrderWrap || !venueOrderList) return;

    const enabled = isVenueOrderEnabled();
    venueOrderWrap.classList.toggle('is-disabled', !enabled);
    venueOrderWrap.dataset.enabled = enabled ? '1' : '0';
    venueOrderList.setAttribute('aria-disabled', enabled ? 'false' : 'true');

    const hint = venueOrderWrap.querySelector('[data-cloudari-sortable-venue-hint]');
    if (hint) {
      hint.textContent = enabled
        ? 'Arrastra y suelta para cambiar el orden.'
        : 'Activa la prioridad manual para poder reordenar los espacios.';
    }

    getVenueOrderItems().forEach((item) => {
      item.draggable = enabled;
      const handle = item.querySelector('[data-cloudari-sortable-venue-handle]');
      if (handle) {
        handle.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        handle.tabIndex = enabled ? 0 : -1;
      }
    });
  }

  function getVenueDragAfterElement(container, clientY) {
    const items = Array.from(
      container.querySelectorAll('[data-cloudari-sortable-venue-item]:not(.is-dragging)')
    );

    return items.reduce(
      (closest, item) => {
        const box = item.getBoundingClientRect();
        const offset = clientY - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
          return { offset, element: item };
        }

        return closest;
      },
      { offset: Number.NEGATIVE_INFINITY, element: null }
    ).element;
  }

  function bindVenueOrderSorting() {
    if (!venueOrderList || !venueOrderWrap) return;

    updateVenueOrderIndices();
    updateVenueOrderState();

    venueOrderToggle?.addEventListener('change', () => {
      updateVenueOrderState();
    });

    venueOrderList.addEventListener('click', (event) => {
      const handle = event.target.closest('[data-cloudari-sortable-venue-handle]');
      if (handle) {
        event.preventDefault();
      }
    });

    venueOrderList.addEventListener('dragstart', (event) => {
      const item = event.target.closest('[data-cloudari-sortable-venue-item]');
      if (!item || !isVenueOrderEnabled()) {
        event.preventDefault();
        return;
      }

      item.classList.add('is-dragging');

      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.venueKey || '');
      }
    });

    venueOrderList.addEventListener('dragend', (event) => {
      const item = event.target.closest('[data-cloudari-sortable-venue-item]');
      if (!item) return;

      item.classList.remove('is-dragging');
      updateVenueOrderIndices();
    });

    venueOrderList.addEventListener('dragover', (event) => {
      if (!isVenueOrderEnabled()) return;

      const draggingItem = venueOrderList.querySelector('.is-dragging');
      if (!draggingItem) return;

      event.preventDefault();

      const afterElement = getVenueDragAfterElement(venueOrderList, event.clientY);
      if (!afterElement) {
        venueOrderList.appendChild(draggingItem);
        return;
      }

      if (afterElement !== draggingItem) {
        venueOrderList.insertBefore(draggingItem, afterElement);
      }
    });

    venueOrderList.addEventListener('drop', (event) => {
      if (!isVenueOrderEnabled()) return;

      event.preventDefault();
      updateVenueOrderIndices();
    });
  }

  if (container) {
    container.addEventListener('click', (event) => {
      const toggleBtn = event.target.closest('[data-toggle-secret]');
      if (toggleBtn) {
        event.preventDefault();
        toggleSecret(toggleBtn);
        return;
      }

      const removeBtn = event.target.closest('[data-remove-integration]');
      if (removeBtn) {
        event.preventDefault();
        const blocks = container.querySelectorAll('[data-integration]');
        if (blocks.length <= 1) {
          alert('Debe haber al menos una integracion.');
          return;
        }
        const block = removeBtn.closest('[data-integration]');
        if (block) {
          block.remove();
          ensureDefaultChecked();
        }
      }
    });
  }

  document.addEventListener('click', (event) => {
    const resetBtn = event.target.closest('[data-reset-style]');
    if (!resetBtn) return;

    event.preventDefault();

    switch (resetBtn.dataset.resetStyle) {
      case 'all-styles':
        resetGlobalStyles();
        resetAllWidgetSections();
        break;
      case 'widget-sections':
        resetAllWidgetSections();
        break;
      case 'widget-section':
        resetWidgetSection(resetBtn.dataset.resetSection || '');
        break;
      default:
        return;
    }

    refreshStylePreview();
  });

  document.addEventListener('input', (event) => {
    if (!event.target.closest('[data-cloudari-style-input]')) {
      return;
    }

    refreshStylePreview();
  });

  document.addEventListener('change', (event) => {
    if (!event.target.closest('[data-cloudari-style-input]')) {
      return;
    }

    refreshStylePreview();
  });

  if (addBtn && container && template) {
    addBtn.addEventListener('click', (event) => {
      event.preventDefault();
      const key = 'int_' + Date.now();
      const html = template.innerHTML.replace(/__KEY__/g, key);
      const wrapper = document.createElement('div');
      wrapper.innerHTML = html.trim();
      const node = wrapper.firstElementChild;
      if (node) {
        container.appendChild(node);
        ensureDefaultChecked();
      }
    });
  }

  ensureDefaultChecked();
  refreshStylePreview();
  bindVenueOrderSorting();
});
