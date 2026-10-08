(function () {
  'use strict';

  var button = document.querySelector('[data-address-search]');
  if (!button) return;

  button.addEventListener('click', function () {
    if (!window.daum || !window.daum.Postcode) {
      window.alert('주소 검색 서비스를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.');
      return;
    }
    new window.daum.Postcode({
      oncomplete: function (data) {
        var zipcode = document.querySelector('[data-signup-zipcode]');
        var address = document.querySelector('[data-signup-address]');
        var detail = document.querySelector('input[name="address2"]');
        var selected = data.roadAddress || data.jibunAddress || '';
        if (zipcode) zipcode.value = data.zonecode || '';
        if (address) address.value = selected;
        if (detail) detail.focus();
      }
    }).open();
  });
})();
