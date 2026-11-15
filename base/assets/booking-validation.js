(function () {
  'use strict';

  var form = document.querySelector('[data-booking-form]');
  if (!form) return;

  var seats = Array.prototype.slice.call(form.querySelectorAll('input[name="seat_ids[]"]'));
  var seatError = document.getElementById('seat-error');
  var seatCount = form.querySelector('[data-seat-count]');
  var bookingTotal = form.querySelector('[data-booking-total]');
  var name = form.elements.customer_name;
  var phone = form.elements.customer_phone;
  var unitCents = Number(bookingTotal.getAttribute('data-unit-cents'));

  function checkedSeats() {
    return seats.filter(function (seat) { return seat.checked; });
  }

  function showSeatError(message) {
    seatError.textContent = message;
    seatError.hidden = !message;
  }

  function updateSummary() {
    var count = checkedSeats().length;
    seatCount.textContent = String(count);
    bookingTotal.textContent = 'Rp ' + Math.round(unitCents * count / 100).toLocaleString('id-ID');
  }

  function validateName() {
    var value = name.value.trim();
    var good = value.length >= 2 && value.length <= 160 && /\p{L}/u.test(value);
    name.setCustomValidity(good || !value ? '' : 'Enter your full name with at least one letter.');
    return good;
  }

  function validatePhone() {
    var value = phone.value.trim();
    var digitCount = (value.match(/[0-9]/g) || []).length;
    var good = /^[+]?[0-9().\- ]{6,40}$/.test(value) && digitCount >= 6;
    phone.setCustomValidity(good || !value ? '' : 'Enter a phone number with at least six digits.');
    return good;
  }

  seats.forEach(function (seat) {
    seat.addEventListener('change', function () {
      if (checkedSeats().length > 12) {
        seat.checked = false;
        showSeatError('Choose no more than 12 seats per reservation.');
      } else {
        showSeatError('');
      }
      updateSummary();
    });
  });
  name.addEventListener('input', validateName);
  phone.addEventListener('input', validatePhone);
  validateName();
  validatePhone();
  updateSummary();

  form.addEventListener('submit', function (event) {
    validateName();
    validatePhone();
    if (checkedSeats().length < 1) {
      event.preventDefault();
      showSeatError('Choose at least one seat before reserving.');
      var firstAvailable = seats.find(function (seat) { return !seat.disabled; });
      if (firstAvailable) firstAvailable.focus();
      return;
    }
    if (!form.reportValidity()) event.preventDefault();
  });
}());
