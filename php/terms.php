<?php
declare(strict_types=1);
require_once __DIR__ . '/_start.php';
require_once __DIR__ . '/../base/includes/layout.php';
page_header('Project terms');
?>
<main class="section booking-page policy-page">
  <div class="heading"><div><p class="eyebrow">Upon a Star Cinema</p><h1>About this <em>project.</em></h1><span>A working cinema-booking application created for IE4727.</span></div></div>
  <article class="panel booking-panel">
    <h2>A student demonstration</h2>
    <p>Upon a Star is an academic cinema concept by Celine Emily Wijaya and Michael Joseph Candra. The programme, venue information and prices demonstrate the application. A reservation or ticket created here does not admit you to a real cinema, and no payment is collected.</p>
    <h2>Using the site</h2>
    <p>Use an account you control. Keep your session and ticket codes private, and do not attempt to access another person's account, change their reservations or interfere with the site. Staff and owner functions are available only to accounts granted those roles.</p>
    <h2>Reservations and availability</h2>
    <p>Seat availability can change while you browse. A reservation is created only after the site confirms it; email delivery is a separate step. This demonstration may be updated, reset or unavailable while it is maintained.</p>
    <h2>Credits and contact</h2>
    <p>Film names and artwork belong to their respective owners. This student project is not an official film-studio or cinema service. Read the <a href="<?= h(base_url('privacy.php')) ?>">privacy notice</a> for information about account and booking data, or contact <a href="mailto:Michaelj005@e.ntu.edu.sg">Michael Joseph Candra</a> about this hosted copy.</p>
  </article>
</main>
<?php page_footer(); ?>
