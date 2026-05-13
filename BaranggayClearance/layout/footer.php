  </div><!-- /page-body -->
  <footer style="padding:.75rem 1.75rem;border-top:1px solid var(--n200);background:#fff;font-size:.7rem;color:var(--n400);text-align:right;">
    &copy; <?= date('Y') ?> Barangay Clearance Management System &mdash; All rights reserved.
  </footer>
</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebar-overlay').classList.toggle('open');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').classList.remove('open');
}
</script>
</body>
</html>
