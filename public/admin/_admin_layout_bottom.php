  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function () {
    const btn = document.getElementById('sidebarCollapseBtn');

    // Desktop: OPEN by default
    // Only collapse if user explicitly chose it before
    try {
      if (
        window.matchMedia('(min-width: 992px)').matches &&
        localStorage.getItem('admin_sidebar_collapsed') === '1'
      ) {
        document.body.classList.add('sidebar-collapsed');
      }
    } catch (e) {}

    btn?.addEventListener('click', () => {
      // Desktop collapse toggle
      if (window.matchMedia('(min-width: 992px)').matches) {
        document.body.classList.toggle('sidebar-collapsed');

        try {
          localStorage.setItem(
            'admin_sidebar_collapsed',
            document.body.classList.contains('sidebar-collapsed') ? '1' : '0'
          );
        } catch (e) {}

        return;
      }

      // Mobile fallback (should not usually hit)
      const el = document.getElementById('adminSidebar');
      const inst = bootstrap.Offcanvas.getOrCreateInstance(el);
      inst.toggle();
    });
  })();
</script>


</body>
</html>
