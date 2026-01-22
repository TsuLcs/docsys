<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';


$title = 'Welcome';
include __DIR__ . '/_layout_top.php';
?>

<section class="hero landing-hero">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-12 col-lg-6">
        <div class="d-inline-flex align-items-center gap-2 mb-3">
          <span class="badge badge-brand">Light theme • Orange brand</span>
          <span class="small-muted">Built for accounting workflows</span>
        </div>

        <h1 class="display-5 fw-bold mb-3">
          Regain operational clarity with
          efficient document tracking
        </h1>

        <p class="lead text-secondary mb-4">
          Submit documents, track every stage, respond to requests, and stay on top of SLAs —
          all in one clean system your team can actually use.
        </p>

        <div class="hero-card p-3 p-md-4">
          <div class="fw-semibold mb-2">Talk to our team</div>
          <div class="row g-2 align-items-stretch">
            <div class="col-12 col-md-7">
              <input class="form-control" placeholder="Your Email Address">
            </div>
            <div class="col-12 col-md-5 d-grid">
              <a class="btn btn-brand h-100" href="/docsys/public/register.php">Get Started</a>
            </div>
          </div>
          <div class="small-muted mt-2">
            Or <a href="/docsys/public/login.php">login</a> if you already have an account.
          </div>
        </div>

        <div class="d-grid gap-2 d-sm-flex mt-3">
          <a class="btn btn-outline-brand" href="#how">How it works</a>
          <a class="btn btn-outline-secondary" href="#services">See services</a>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="soft-card p-3 p-md-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="fw-semibold">Live case tracking</div>
            <span class="badge badge-brand">100% transparency</span>
          </div>

          <div class="row g-3">
            <div class="col-12">
              <div class="p-3 rounded-4 border bg-light">
                <div class="d-flex justify-content-between">
                  <div class="fw-semibold text-break">TAX-VAT-20260121-000001</div>
                  <span class="badge bg-info text-dark">Received</span>
                </div>
                <div class="small-muted mt-1">SLA Due: 24h • Assigned: Staff</div>
              </div>
            </div>

            <div class="col-12">
              <div class="p-3 rounded-4 border bg-white">
                <div class="fw-semibold mb-2">Checklist</div>
                <div class="d-flex flex-wrap gap-2">
                  <span class="badge bg-success">VAT Returns</span>
                  <span class="badge bg-success">Sales Books</span>
                  <span class="badge bg-warning text-dark">Purchase Books</span>
                  <span class="badge bg-secondary">2307 (optional)</span>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="p-3 rounded-4 border bg-white">
                <div class="fw-semibold mb-1">Next action</div>
                <div class="small-muted">Upload missing Purchase Books to continue validation.</div>
              </div>
            </div>
          </div>

        </div>
        <div class="small text-center small-muted mt-2">A clean UI that matches real accounting workflows</div>
      </div>
    </div>
  </div>
</section>

<section class="proofbar py-4">
  <div class="container">
    <div class="row align-items-center g-3">
      <div class="col-12 col-lg-8">
        <div class="fs-4 fw-bold">Trusted tracking for service teams</div>
        <div class="text-white-50">Transparent stages • SLA timers • File versioning • Audit trail</div>
      </div>
      <div class="col-12 col-lg-4 text-center text-lg-end">
        <div class="stars">★★★★★</div>
        <div class="text-white-50 small">Built for reliability</div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="services">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-12 col-lg-6">
        <h2 class="fw-bold mb-2">Stay confident and in control</h2>
        <p class="text-secondary mb-4">
          No more “where is the file?” and “who’s handling this?”. Your clients and staff get a single source of truth.
        </p>

        <div class="vstack gap-3">
          <div class="d-flex gap-3">
            <div class="icon-bubble">✓</div>
            <div>
              <div class="fw-semibold">Client submissions</div>
              <div class="small-muted">Structured intake with required docs per service.</div>
            </div>
          </div>
          <div class="d-flex gap-3">
            <div class="icon-bubble">✓</div>
            <div>
              <div class="fw-semibold">SLA & overdue alerts</div>
              <div class="small-muted">Track time-in-state and breach visibility.</div>
            </div>
          </div>
          <div class="d-flex gap-3">
            <div class="icon-bubble">✓</div>
            <div>
              <div class="fw-semibold">File versioning</div>
              <div class="small-muted">New uploads become versions per requirement slot.</div>
            </div>
          </div>
          <div class="d-flex gap-3">
            <div class="icon-bubble">✓</div>
            <div>
              <div class="fw-semibold">Audit trail</div>
              <div class="small-muted">Every change logged: state, ETA, assignment, comments.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="soft-card p-4">
          <div class="fw-semibold mb-3">What you can support</div>
          <div class="row g-2">
            <div class="col-12 col-sm-6"><span class="badge badge-brand w-100 text-center py-2">Tax Filing</span></div>
            <div class="col-12 col-sm-6"><span class="badge badge-brand w-100 text-center py-2">VAT Compliance</span></div>
            <div class="col-12 col-sm-6"><span class="badge badge-brand w-100 text-center py-2">Audit Engagements</span></div>
            <div class="col-12 col-sm-6"><span class="badge badge-brand w-100 text-center py-2">Enrollment / Gov</span></div>
          </div>
          <div class="small-muted mt-3">You control requirements and SLA per service (admin panel next).</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section bg-light" id="how">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="fw-bold mb-2">Get started in 3 steps</h2>
      <div class="small-muted">Simple client flow, structured staff processing.</div>
    </div>

    <div class="row g-3 justify-content-center">
      <div class="col-12 col-lg-8">
        <div class="step">
          <div class="num">1</div>
          <div>
            <div class="fw-semibold">Submit your case and upload requirements</div>
            <div class="small-muted">The system enforces required fields and files based on the service type.</div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-8">
        <div class="step">
          <div class="num">2</div>
          <div>
            <div class="fw-semibold">Staff validates and requests missing items</div>
            <div class="small-muted">Tasks drive “Waiting for Client” and everything is logged for accountability.</div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-8">
        <div class="step">
          <div class="num">3</div>
          <div>
            <div class="fw-semibold">Track progress with SLA visibility</div>
            <div class="small-muted">Know what stage it’s in, what’s next, and if anything is overdue.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="text-center mt-4">
      <a class="btn btn-brand px-4" href="/docsys/public/register.php">Create Account</a>
      <a class="btn btn-outline-brand px-4 ms-2" href="/docsys/public/login.php">Login</a>
    </div>
  </div>
</section>

<footer class="footer py-5">
  <div class="container width->
    <div class="row g-3 align-items-center">
      <div class="col-12 col-lg-6">
        <div class="fw-bold text-white">DocFlow</div>
        <div class="small text-white-50">Modern document tracking for service teams.</div>
      </div>
      <div class="col-12 col-lg-6 text-lg-end">
        <div class="small text-white-50">© <?php echo date('Y'); ?> DocFlow</div>
      </div>
    </div>
  </div>
</footer>

<?php include __DIR__ . '/_layout_bottom.php'; ?>