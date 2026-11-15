<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

page_header('Plan your visit', 'visit');
?>
<main>
  <section class="section visit-intro" aria-labelledby="visit-title">
    <div class="visit-intro-heading">
      <div>
        <p class="eyebrow">Your visit starts here</p>
        <h1 id="visit-title">Make a night of <em>the movies.</em></h1>
      </div>
      <div class="visit-intro-copy">
        <p>Find your film, choose a time, and settle in. We want every visit to feel simple from the first hello to the closing credits.</p>
        <a class="primary" href="<?= h(base_url('showtimes.php')) ?>">Explore showtimes →</a>
      </div>
    </div>
    <figure class="visit-entrance">
      <img src="<?= h(base_url('assets/visit/arrival.webp')) ?>" alt="Concept illustration of the Upon a Star cinema entrance, with two doorways, film posters, and a Mickey Mouse statue between them" width="1774" height="887" fetchpriority="high">
      <figcaption>Concept illustration of the Upon a Star entrance.</figcaption>
    </figure>
  </section>

  <section class="section visit-location-section" aria-labelledby="visit-location">
    <div class="visit-location-card">
      <div>
        <p class="eyebrow">Find your way</p>
        <h2 id="visit-location">A little magic <em>in the city.</em></h2>
        <p>Step into PIK Avenue and leave the city behind for a world of stories. Find Upon a Star on Level 5, ready for your next movie night.</p>
      </div>
      <div class="visit-location-address">
        <span>Location</span>
        <address>
          <strong>PIK Avenue</strong><br>
          Level 5, #05-01<br>
          Jakarta, Indonesia
        </address>
      </div>
    </div>
  </section>

  <section class="section visit-essentials-section" aria-labelledby="visit-essentials">
    <div class="heading">
      <div><p>The essentials</p><h2 id="visit-essentials">Plan your <em>visit.</em></h2></div>
      <span>Everything you need before the lights dim.</span>
    </div>
    <div class="visit-details visit-details-large">
      <article><b>Opening hours</b><span>Daily · 10:00–22:30. Last entry follows the final scheduled screening. Check the showtime table for the exact start of your film.</span></article>
      <article><b>When to arrive</b><span>Come about 20 minutes before your screening. That leaves time to find the cinema room, speak to the desk, and get comfortable.</span></article>
      <article><b>Your booking</b><span>Keep your booking reference and each seat's ticket code from the confirmation page. Bring them to the cinema desk if you need help finding your assigned seats.</span></article>
    </div>
  </section>

  <section class="section visit-room-section" aria-labelledby="visit-rooms">
    <div class="heading">
      <div><p>Choose your setting</p><h2 id="visit-rooms">Three ways to watch <em>together.</em></h2></div>
      <span>Your exact room is shown in the schedule and again before you book.</span>
    </div>
    <div class="visit-room-grid">
      <article class="visit-room-card">
        <img src="<?= h(base_url('assets/visit/premier.webp')) ?>" alt="Concept interior of a Premier auditorium with gold-lit walls and reclining seats" width="1600" height="900" loading="lazy" decoding="async">
        <div><span>36 assigned seats</span><h3>Premier</h3><p>An intimate room with premium sound, used for selected special screenings.</p></div>
      </article>
      <article class="visit-room-card">
        <img src="<?= h(base_url('assets/visit/normal.webp')) ?>" alt="Concept interior of a Normal auditorium with rows of red cinema seats" width="1600" height="900" loading="lazy" decoding="async">
        <div><span>120 assigned seats</span><h3>Normal</h3><p>A full-size screen for everyday showings and the broadest programme.</p></div>
      </article>
      <article class="visit-room-card">
        <img src="<?= h(base_url('assets/visit/small.webp')) ?>" alt="Concept interior of a Small auditorium with navy seats and a bright screen" width="1600" height="900" loading="lazy" decoding="async">
        <div><span>60 assigned seats</span><h3>Small</h3><p>A comfortable room for documentaries, family films, and quieter stories.</p></div>
      </article>
    </div>
  </section>

  <section class="section visit-help-section" aria-labelledby="visit-help">
    <div class="visit-help-card">
      <div>
        <p class="eyebrow">We are here to help</p>
        <h2 id="visit-help">A comfortable visit <em>for everyone.</em></h2>
      </div>
      <div>
        <p>For ticket changes, accessibility requirements, or group visits, speak to the team at the cinema desk before your screening. We can help you find your room and seats.</p>
        <a href="<?= h(base_url('showtimes.php')) ?>">See the full schedule →</a>
      </div>
    </div>
  </section>
</main>
<?php page_footer(); ?>
