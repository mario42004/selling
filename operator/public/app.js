(() => {
  const supplierNet = document.getElementById('supplier-net-price');
  const supplierVatRate = document.getElementById('supplier-vat-rate');
  const supplierVatAmount = document.getElementById('supplier-vat-amount');
  const supplierTotalPrice = document.getElementById('supplier-total-price');
  const suggestedCatalogPrice = document.getElementById('suggested-catalog-price');
  if (supplierNet && supplierVatRate && supplierVatAmount && supplierTotalPrice && suggestedCatalogPrice) {
    const updateSupplierPrices = () => {
      const net = Number.parseFloat(supplierNet.value) || 0;
      const vatRate = Number.parseFloat(supplierVatRate.value) || 0;
      const vat = Math.round(net * vatRate) / 100;
      const total = Math.round((net + vat) * 100) / 100;
      supplierVatAmount.textContent = vat.toFixed(2).replace('.', ',');
      supplierTotalPrice.textContent = total.toFixed(2).replace('.', ',');
      suggestedCatalogPrice.textContent = (Math.round(total * 130) / 100).toFixed(2).replace('.', ',');
    };
    supplierNet.addEventListener('input', updateSupplierPrices);
    supplierVatRate.addEventListener('input', updateSupplierPrices);
    updateSupplierPrices();
  }

  const roles = Array.from(document.querySelectorAll('#user-role-list input[name="role_codes[]"]'));
  const cityGroup = document.getElementById('user-city-assignments');
  const storeGroup = document.getElementById('user-store-assignments');
  if (roles.length === 0 || !cityGroup || !storeGroup) return;

  const updateAssignments = () => {
    const scopes = roles.filter((role) => role.checked).map((role) => role.dataset.scope);
    const globalScope = scopes.includes('GLOBAL');
    const needsCity = !globalScope && scopes.includes('CITY');
    const needsStore = !globalScope && scopes.includes('STORE');
    cityGroup.hidden = !needsCity;
    storeGroup.hidden = !needsStore;
    cityGroup.querySelector('select').disabled = !needsCity;
    storeGroup.querySelector('select').disabled = !needsStore;
  };

  roles.forEach((role) => role.addEventListener('change', updateAssignments));
  updateAssignments();
})();
