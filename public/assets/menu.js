// Dropdown menu utama (induk > anak). Markup dari menu_nav_html() di akses_lib.php.
// Panel memakai position:fixed supaya tidak terpotong oleh nav yang bisa digeser di layar kecil;
// posisinya dihitung saat dibuka. Desktop: buka saat disorot atau diklik. Layar sentuh: ketuk.
(function() {
    var nav = document.querySelector('.top nav');
    if (!nav) return;
    var dds = Array.prototype.slice.call(nav.querySelectorAll('.dd'));
    if (!dds.length) return;

    var bisaSorot = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var timer = null;

    function tutup(kecuali) {
        dds.forEach(function(d) {
            if (d === kecuali) return;
            d.classList.remove('buka');
            d.querySelector('.dd-t').setAttribute('aria-expanded', 'false');
        });
    }

    function buka(d) {
        clearTimeout(timer);
        tutup(d);
        var t = d.querySelector('.dd-t'), m = d.querySelector('.dd-m'), r = t.getBoundingClientRect();
        d.classList.add('buka');                       // tampilkan dulu supaya lebarnya bisa diukur
        t.setAttribute('aria-expanded', 'true');
        m.style.top = Math.round(r.bottom) + 'px';
        m.style.left = Math.max(8, Math.min(Math.round(r.left), window.innerWidth - m.offsetWidth - 8)) + 'px';
    }

    dds.forEach(function(d) {
        var t = d.querySelector('.dd-t');

        t.addEventListener('click', function() {
            if (d.classList.contains('buka')) {
                if (!bisaSorot) tutup();               // di desktop sudah terbuka karena disorot: biarkan
            } else {
                buka(d);
            }
        });

        if (bisaSorot) {
            d.addEventListener('mouseenter', function() { buka(d); });
            d.addEventListener('mouseleave', function() { timer = setTimeout(function() { tutup(); }, 150); });
        }

        d.addEventListener('keydown', function(e) {
            var item = Array.prototype.slice.call(d.querySelectorAll('.dd-m a')),
                i = item.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!d.classList.contains('buka')) buka(d);
                item[(i + 1) % item.length].focus();
            } else if (e.key === 'ArrowUp' && i >= 0) {
                e.preventDefault();
                item[(i - 1 + item.length) % item.length].focus();
            } else if (e.key === 'Escape') {
                tutup();
                t.focus();
            }
        });
    });

    document.addEventListener('click', function(e) { if (!e.target.closest('.dd')) tutup(); });
    window.addEventListener('scroll', function() { tutup(); }, { passive: true });
    window.addEventListener('resize', function() { tutup(); });
    nav.addEventListener('scroll', function() { tutup(); }, { passive: true });
})();
