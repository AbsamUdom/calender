(function(){
  var trigger = document.getElementById('profileIcon') || document.getElementById('userProfile');
  var dropdown = document.getElementById('profileDropdown');
  if (!trigger || !dropdown) return;

  function closeDropdown() {
    dropdown.classList.remove('show');
  }

  function toggleDropdown(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    dropdown.classList.toggle('show');
  }

  trigger.addEventListener('click', toggleDropdown);

  document.addEventListener('click', function (event) {
    if (!event.target.closest('#profileIcon') && !event.target.closest('#userProfile')) {
      closeDropdown();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeDropdown();
    }
  });
})();
