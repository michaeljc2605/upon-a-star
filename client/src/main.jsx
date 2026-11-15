import { Html5QrcodeScanner } from "html5-qrcode";
import QRCode from "qrcode";
import React, { useEffect, useMemo, useState } from "react";
import { createRoot } from "react-dom/client";
import "./styles.css";

const API = import.meta.env.VITE_API_URL || "http://localhost:4000/api";
const PHP_SITE = import.meta.env.VITE_PHP_SITE_URL || `${window.location.protocol}//${window.location.hostname}/SilverVillage`;
const money = (value) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(Number(value));
const dateTime = (value) =>
  new Date(value).toLocaleString("en-GB", {
    weekday: "short",
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
const screeningDate = (value) => new Date(value).toISOString().slice(0, 10);
const screeningDateLabel = (value) =>
  new Date(`${value}T12:00:00`).toLocaleDateString("en-GB", {
    weekday: "short",
    day: "numeric",
    month: "short",
  });
const movieDateValue = (value) => value ? String(value).slice(0, 10) : "";
const movieDateLabel = (value) => value
  ? new Date(`${movieDateValue(value)}T12:00:00`).toLocaleDateString("en-GB", {
      day: "numeric",
      month: "short",
      year: "numeric",
    })
  : "Date not set";
function MovieLifecycleLabel({ movie }) {
  if (movie.lifecycle_status === "arriving_soon") {
    return (
      <div className="movie-lifecycle arriving-soon">
        <b>Arriving soon</b>
        <span>{movieDateLabel(movie.run_start_date)}–{movieDateLabel(movie.run_end_date)}</span>
        {!Number(movie.tickets_on_sale) && <small>Tickets from {movieDateLabel(movie.sales_open_date)}</small>}
        {Boolean(Number(movie.tickets_on_sale)) && <small>Pre-sale now open</small>}
      </div>
    );
  }
  return (
    <div className="movie-lifecycle ongoing">
      <b>Ongoing</b>
      {movie.run_end_date && <span>Until {movieDateLabel(movie.run_end_date)}</span>}
    </div>
  );
}
async function json(response) {
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.error || "Something went wrong.");
  return body;
}
const MOVIE_THEME_TEMPLATES = [
  { key: "enchanted_castle", label: "Enchanted Castle", description: "Royal fairytales, ballroom stories and classic magic.", motion: "Lanterns, warm sparkles and enchanted light", examples: "Tangled · Cinderella · Beauty and the Beast" },
  { key: "legends_and_courage", label: "Legends & Courage", description: "Brave heroes, ancient kingdoms and dramatic journeys.", motion: "Snow, mountain mist and glowing embers", examples: "Frozen · Mulan · Brave" },
  { key: "ocean_and_islands", label: "Ocean & Islands", description: "Underwater worlds, island adventures and coastal stories.", motion: "Bubbles, water shimmer and wave light", examples: "Moana · The Little Mermaid · Finding Nemo" },
  { key: "hero_city", label: "Hero City", description: "Superhero teams, powerful cities and high-energy action.", motion: "Energy trails, searchlights and skyline glow", examples: "Marvel · Big Hero 6 · The Incredibles" },
  { key: "tech_and_arcade", label: "Tech & Arcade", description: "Machines, racing worlds and colourful digital adventures.", motion: "Pixels, neon grids and speed lines", examples: "WALL-E · Cars · Wreck-It Ralph" },
  { key: "worlds_of_imagination", label: "Worlds of Imagination", description: "Playful hidden worlds, memories and childhood wonder.", motion: "Memory orbs, dream shapes and soft colour", examples: "Toy Story · Inside Out · Monsters University" },
  { key: "sky_adventure", label: "Sky Adventure", description: "Flying stories, distant destinations and journeys above the clouds.", motion: "Clouds, stars and floating balloons", examples: "Peter Pan · Up" },
  { key: "culinary_magic", label: "Culinary Magic", description: "Food, music, restaurants and ambitious dreamers.", motion: "Curling steam, fireflies and warm restaurant light", examples: "Ratatouille · The Princess and the Frog" },
  { key: "animal_worlds", label: "Animal Worlds", description: "Wild kingdoms and lively cities built around animal characters.", motion: "Drifting leaves, sunrise glow and natural movement", examples: "The Lion King · Zootopia" },
];
const MOVIE_THEME_KEYS = new Set(MOVIE_THEME_TEMPLATES.map((template) => template.key));
const MOVIE_MAGIC_RULES = [
  { theme: "legends_and_courage", pattern: /frozen|mulan|brave|arendelle|snow|ice|winter/ },
  { theme: "ocean_and_islands", pattern: /moana|ocean|sea|island|coast|mermaid|nemo|stitch/ },
  { theme: "hero_city", pattern: /marvel|hero|incredibles/ },
  { theme: "tech_and_arcade", pattern: /wall-e|cars|mcqueen|ralph|arcade/ },
  { theme: "worlds_of_imagination", pattern: /toy story|inside out|monsters university/ },
  { theme: "sky_adventure", pattern: /peter pan|\bup\b|flying|balloon/ },
  { theme: "culinary_magic", pattern: /ratatouille|princess and the frog|restaurant|cooking/ },
  { theme: "animal_worlds", pattern: /lion king|zootopia|animal/ },
];
function movieMagicTheme(movie) {
  if (MOVIE_THEME_KEYS.has(movie?.theme_key)) return movie.theme_key;
  const story = [movie?.title, movie?.slug, movie?.genre, movie?.synopsis]
    .filter(Boolean)
    .join(" ")
    .toLowerCase();
  return MOVIE_MAGIC_RULES.find(({ pattern }) => pattern.test(story))?.theme || (movie ? "enchanted_castle" : "storybook");
}
function MovieMagic({ theme }) {
  if (theme === "enchanted_castle") {
    return (
      <div className="movie-magic enchanted-castle-magic lantern-magic" aria-hidden="true">
        {Array.from({ length: 11 }, (_, index) => <i key={index} />)}
      </div>
    );
  }
  if (theme === "ocean_and_islands") {
    return (
      <div className="movie-magic ocean-and-islands-magic ocean-magic" aria-hidden="true">
        {Array.from({ length: 16 }, (_, index) => <i key={index} />)}
      </div>
    );
  }
  if (theme === "legends_and_courage") {
    return (
      <div className="movie-magic legends-and-courage-magic snow-magic" aria-hidden="true">
        {Array.from({ length: 26 }, (_, index) => (
          <i
            key={index}
            style={{
              "--snow-left": `${45 + ((index * 17) % 55)}%`,
              "--snow-size": `${4 + ((index * 7) % 8)}px`,
              "--snow-speed": `${8 + ((index * 5) % 9)}s`,
              "--snow-delay": `${-((index * 3) % 15)}s`,
              "--snow-drift": `${-34 + ((index * 13) % 68)}px`,
            }}
          />
        ))}
      </div>
    );
  }
  if (MOVIE_THEME_KEYS.has(theme)) {
    const particleCounts = { hero_city: 12, tech_and_arcade: 22, worlds_of_imagination: 16, sky_adventure: 11, culinary_magic: 14, animal_worlds: 18 };
    return (
      <div className={`movie-magic template-magic ${theme.replaceAll("_", "-")}-magic`} aria-hidden="true">
        {Array.from({ length: particleCounts[theme] || 14 }, (_, index) => (
          <i key={index} style={{ "--magic-left": `${46 + ((index * 19) % 53)}%`, "--magic-top": `${5 + ((index * 23) % 86)}%`, "--magic-size": `${7 + ((index * 11) % 21)}px`, "--magic-speed": `${7 + ((index * 5) % 10)}s`, "--magic-delay": `${-((index * 3) % 14)}s`, "--magic-drift": `${-42 + ((index * 17) % 84)}px` }} />
        ))}
      </div>
    );
  }
  return <div className="movie-magic storybook-magic" aria-hidden="true" />;
}
function MovieThemeSelect({ value, onChange, compact = false }) {
  const selected = MOVIE_THEME_TEMPLATES.find((template) => template.key === value) || MOVIE_THEME_TEMPLATES[0];
  return (
    <div className={`movie-theme-select ${compact ? "compact" : ""}`}>
      <label>Experience template<select value={selected.key} onChange={(event) => onChange(event.target.value)}>{MOVIE_THEME_TEMPLATES.map((template) => <option value={template.key} key={template.key}>{template.label}</option>)}</select></label>
      {!compact && <div className={`movie-theme-preview preview-${selected.key.replaceAll("_", "-")}`}><MovieMagic key={selected.key} theme={selected.key} /><div><small>Live animation preview</small><b>{selected.label}</b><span>{selected.description}</span><em>{selected.motion}</em><small>Suggested: {selected.examples}</small></div></div>}
    </div>
  );
}
function TitleWithAccent({ children }) {
  const words = String(children || "").trim().split(/\s+/).filter(Boolean);
  if (words.length < 2) return <>{words[0] || ""}</>;
  const accent = words.pop();
  return <>{words.join(" ")} <em>{accent}</em></>;
}
const SPARKLE_GLYPHS = ["✦", "✧", "✶"];
function burstSparkles(x, y) {
  const burst = document.createElement("div");
  burst.className = "sparkle-burst";
  burst.style.left = `${x}px`;
  burst.style.top = `${y}px`;
  const count = 10;
  for (let i = 0; i < count; i++) {
    const particle = document.createElement("span");
    particle.className = "sparkle-particle";
    const angle = (360 / count) * i + (Math.random() * 24 - 12);
    const distance = 34 + Math.random() * 34;
    const rad = (angle * Math.PI) / 180;
    particle.style.setProperty("--tx", `${Math.cos(rad) * distance}px`);
    particle.style.setProperty("--ty", `${Math.sin(rad) * distance}px`);
    particle.style.animationDelay = `${Math.random() * 70}ms`;
    particle.style.fontSize = `${0.7 + Math.random() * 0.6}rem`;
    particle.textContent = SPARKLE_GLYPHS[i % SPARKLE_GLYPHS.length];
    burst.appendChild(particle);
  }
  document.body.appendChild(burst);
  setTimeout(() => burst.remove(), 750);
}
function TicketQr({ value }) {
  const [src, setSrc] = useState("");
  useEffect(() => { QRCode.toDataURL(value, { width: 150, margin: 1, color: { dark: "#12194a", light: "#f7ece9" } }).then(setSrc).catch(() => setSrc("")); }, [value]);
  return src ? <img src={src} alt="Ticket QR code" /> : null;
}
function HoldCountdown({ expiresAt, label = "Hold expires in" }) {
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    if (!expiresAt) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [expiresAt]);
  if (!expiresAt) return null;
  const remainingMs = new Date(expiresAt).getTime() - now;
  if (remainingMs <= 0) return <p className="hold-countdown expired">Hold expired — please reselect your seats.</p>;
  const totalSeconds = Math.floor(remainingMs / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = String(totalSeconds % 60).padStart(2, "0");
  return (
    <p className={`hold-countdown ${remainingMs < 60_000 ? "urgent" : ""}`}>
      {label} {minutes}:{seconds}
    </p>
  );
}
function StaffQrScanner({ onScan }) {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    if (!open) return;
    const scanner = new Html5QrcodeScanner("staff-qr-reader", { fps: 10, qrbox: { width: 240, height: 240 } }, false);
    scanner.render((code) => { scanner.clear().catch(() => {}); setOpen(false); onScan(code); }, () => {});
    return () => { scanner.clear().catch(() => {}); };
  }, [open]);
  return <div className="scanner-control"><button type="button" className="primary" onClick={() => setOpen((value) => !value)}>{open ? "Stop camera" : "Scan ticket QR"}</button>{open && <div id="staff-qr-reader" />}</div>;
}
function routeOf() {
  return window.location.pathname === "/" ? "/" : window.location.pathname;
}

function App() {
  const [route, setRoute] = useState(routeOf());
  const [featuredIndex, setFeaturedIndex] = useState(0);
  const [catalog, setCatalog] = useState({ shows: [], performances: [] });
  const [movies, setMovies] = useState([]);
  const [movie, setMovie] = useState(null);
  const [cart, setCart] = useState([]);
  const [seatPicker, setSeatPicker] = useState(null);
  const [cartOpen, setCartOpen] = useState(false);
  const [form, setForm] = useState({ customerPhone: "" });
  const [csrf, setCsrf] = useState("");
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [result, setResult] = useState(null);
  const [user, setUser] = useState(null);
  const [bookings, setBookings] = useState([]);
  const [ownerData, setOwnerData] = useState({ rooms: [], movies: [], accounts: [], screenings: [], summary: null, nextScreening: null });
  const [ticketResults, setTicketResults] = useState(null);
  const refreshCsrf = async () => {
    const token = await fetch(`${API}/auth/csrf`, { credentials: "include" }).then(json);
    setCsrf(token.token);
    return token.token;
  };
  const fetchWithCsrf = async (url, options = {}) => {
    const send = (token) => fetch(url, {
      ...options,
      credentials: "include",
      headers: { ...(options.headers || {}), "X-CSRF-Token": token },
    });
    let response = await send(csrf || await refreshCsrf());
    if (response.status === 403) response = await send(await refreshCsrf());
    return response;
  };
  const navigate = (path) => {
    window.history.pushState({}, "", path);
    setRoute(path);
    window.scrollTo({ top: 0, behavior: "smooth" });
  };
  const goToSection = (id) => {
    navigate("/");
    window.setTimeout(
      () => document.getElementById(id)?.scrollIntoView({ behavior: "smooth" }),
      0,
    );
  };
  useEffect(() => {
    const pop = () => setRoute(routeOf());
    window.addEventListener("popstate", pop);
    return () => window.removeEventListener("popstate", pop);
  }, []);
  useEffect(() => {
    if (!notice) return undefined;
    const timer = window.setTimeout(() => setNotice(""), 4500);
    return () => window.clearTimeout(timer);
  }, [notice]);
  useEffect(() => {
    const onClick = (e) => burstSparkles(e.clientX, e.clientY);
    document.addEventListener("click", onClick);
    return () => document.removeEventListener("click", onClick);
  }, []);
  useEffect(() => {
    const savedScroll = window.sessionStorage.getItem("silver-staff-scroll-y");
    if (!savedScroll || routeOf() !== "/staff") return;
    window.sessionStorage.removeItem("silver-staff-scroll-y");
    window.requestAnimationFrame(() => window.scrollTo({ top: Number(savedScroll), behavior: "auto" }));
  }, []);
  useEffect(() => {
    Promise.all([
      fetch(`${API}/catalog`).then(json),
      fetch(`${API}/movies`).then(json),
      fetch(`${API}/auth/csrf`, { credentials: "include" }).then(json),
      fetch(`${API}/auth/me`, { credentials: "include" }).then((r) =>
        r.ok ? r.json() : null,
      ),
    ])
      .then(([c, m, token, me]) => {
        setCatalog(c);
        setMovies(m.movies);
        setCsrf(token.token);
        setUser(me?.user || null);
      })
      .catch((e) => setError(e.message));
  }, []);
  useEffect(() => {
    const slug = route.match(/^\/movies\/([^/]+)$/)?.[1];
    if (!slug) return setMovie(null);
    fetch(`${API}/movies/${slug}`)
      .then(json)
      .then(setMovie)
      .catch((e) => setError(e.message));
  }, [route]);
  useEffect(() => {
    if (!user) return;
    if (["owner", "staff", "manager"].includes(user.role))
      Promise.all([
        user.role === "owner" ? fetch(`${API}/admin/bookings`, { credentials: "include" }).then(json) : Promise.resolve({ bookings: [] }),
        user.role === "owner" ? fetch(`${API}/admin/rooms`, { credentials: "include" }).then(json) : Promise.resolve({ rooms: [] }),
        user.role === "owner" ? fetch(`${API}/admin/movies`, { credentials: "include" }).then(json) : Promise.resolve({ movies: [] }),
        user.role === "owner" ? fetch(`${API}/admin/accounts`, { credentials: "include" }).then(json) : Promise.resolve({ accounts: [] }),
        user.role === "owner" ? fetch(`${API}/admin/screenings`, { credentials: "include" }).then(json) : Promise.resolve({ screenings: [] }),
        user.role === "owner" ? fetch(`${API}/admin/summary`, { credentials: "include" }).then(json) : Promise.resolve({ summary: null, nextScreening: null }),
      ])
        .then(([b, r, m, a, s, d]) => { setBookings(b.bookings || []); setOwnerData({ rooms: r.rooms, movies: m.movies, accounts: a.accounts || [], screenings: s.screenings || [], summary: d.summary, nextScreening: d.nextScreening }); })
        .catch((e) => setError(e.message));
  }, [user]);
  useEffect(() => {
    if (!user) return;
    const pending = window.sessionStorage.getItem("silver-pending-seating");
    if (!pending) return;
    window.sessionStorage.removeItem("silver-pending-seating");
    try { const { screening, movie: pendingMovie } = JSON.parse(pending); openSeats(screening, pendingMovie); } catch { window.sessionStorage.removeItem("silver-pending-seating"); }
  }, [user]);
  const cartTotal = cart.reduce(
    (sum, item) =>
      sum + Number(item.screening.unit_price) * item.seatIds.length,
    0,
  );
  const openSeats = async (screening, movieInfo) => {
    if (!Number(screening.booking_open)) {
      setNotice(`Tickets open ${movieDateLabel(movieInfo.sales_open_date)}.`);
      return;
    }
    if (!user) {
      window.sessionStorage.setItem("silver-pending-seating", JSON.stringify({ screening, movie: movieInfo }));
      setNotice("Sign in with Google to hold your seats. We’ll return you to this screening.");
      startGoogleSignIn();
      return;
    }
    setError("");
    try {
      const data = await fetch(`${API}/screenings/${screening.id}/seats`, {
        credentials: "include",
      }).then(
        json,
      );
      const existing = cart.find((item) => item.screening.id === screening.id);
      setSeatPicker({
        ...data,
        movie: movieInfo,
        selected: existing?.seatIds || [],
      });
    } catch (e) {
      setError(e.message);
    }
  };
  const toggleSeat = async (id) => {
    const next = seatPicker.selected.includes(id) ? seatPicker.selected.filter((seatId) => seatId !== id) : [...seatPicker.selected, id];
    setError("");
    try { const hold = await fetchWithCsrf(`${API}/screenings/${seatPicker.screening.id}/hold`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ seatIds: next }) }).then(json); setSeatPicker((current) => ({ ...current, selected: hold.heldSeatIds, holdExpiresAt: hold.expiresAt })); }
    catch (e) { setError(e.message); }
  };
  const addToCart = () => {
    if (!seatPicker.selected.length)
      return setError(
        "Choose at least one seat before adding tickets to your cart.",
      );
    const { screening, movie: movieInfo, selected } = seatPicker;
    setCart((current) => [
      ...current.filter((item) => item.screening.id !== screening.id),
      {
        screening,
        movie: movieInfo,
        seatIds: selected,
        seats: seatPicker.seats.filter((seat) => selected.includes(seat.id)),
        holdExpiresAt: seatPicker.holdExpiresAt,
      },
    ]);
    setSeatPicker(null);
    setCartOpen(true);
  };
  const checkout = async (event) => {
    event.preventDefault();
    setError("");
    if (!cart.length) return;
    try {
      const body = {
        ...form,
        items: cart.map((item) => ({
          performanceId: item.screening.id,
          seatIds: item.seatIds,
        })),
      };
      const data = await fetchWithCsrf(`${API}/bookings`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      }).then(json);
      setResult(data);
      setCart([]);
      setNotice("Your seats are booked. Each seat ticket is ready in My bookings.");
    } catch (e) {
      setError(e.message);
    }
  };
  const startGoogleSignIn = () => { window.location.assign(`${API}/auth/google`); };
  const logout = async () => {
    await fetchWithCsrf(`${API}/auth/logout`, {
      method: "POST",
    });
    setUser(null);
    navigate("/");
  };
  const findTickets = async () => {
    setError("");
    setTicketResults(null);
    try {
      const data = await fetch(`${API}/tickets`, { credentials: "include" }).then(json);
      setTicketResults(data.tickets);
    } catch (e) {
      setError(e.message);
    }
  };
  const showHome = route === "/";
  const showMovies = route === "/movies";
  const showDetail = route.startsWith("/movies/");
  const showTickets = route === "/tickets";
  const showProfile = route === "/profile";
  const showStaff = route === "/staff";
  const previews = catalog.shows.slice(0, 6);
  const featuredMovie = previews[featuredIndex] || previews[0] || null;
  const featuredTheme = movieMagicTheme(featuredMovie);
  const changeFeaturedMovie = (direction) => {
    if (!previews.length) return;
    setFeaturedIndex((current) => (current + direction + previews.length) % previews.length);
  };
  useEffect(() => {
    if (!showHome || previews.length < 2) return undefined;
    const timer = window.setInterval(() => {
      setFeaturedIndex((current) => (current + 1) % previews.length);
    }, 8000);
    return () => window.clearInterval(timer);
  }, [showHome, previews.length]);
  useEffect(() => {
    if (featuredIndex >= previews.length && previews.length) setFeaturedIndex(0);
  }, [featuredIndex, previews.length]);
  return (
    <div className="cinema-shell">
      <div className="starfield" aria-hidden="true">
        <span className="star-layer-1" />
        <span className="star-layer-2" />
        <span className="star-layer-3" />
        <span className="shoot shoot-1" />
        <span className="shoot shoot-2" />
        <span className="shoot shoot-3" />
        <span className="shoot shoot-4" />
        <span className="shoot shoot-5" />
        <span className="shoot shoot-6" />
      </div>
      <header className="cinema-header">
        <button className="brand" onClick={() => navigate("/")}>
          <b><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 2 L14.3 9.7 L22 12 L14.3 14.3 L12 22 L9.7 14.3 L2 12 L9.7 9.7 Z" /></svg></b>
          <span>
            <strong>Upon a <em>Star</em></strong>
            <small>MOVIES UNDER THE STARS</small>
          </span>
        </button>
        <nav>
          <button onClick={() => navigate("/movies")}>Movies</button>
          <button onClick={() => navigate("/tickets")}>My tickets</button>
        </nav>
        <div className="header-actions">
          <button className="ticket-button" onClick={() => setCartOpen(true)}>
            Cart
            <span>{cart.reduce((n, item) => n + item.seatIds.length, 0)}</span>
          </button>
          {user && <button className="staff-button" onClick={() => navigate("/profile")}>Profile</button>}
          {["owner", "staff", "manager"].includes(user?.role) && <button className="staff-button" onClick={() => navigate("/staff")}>Staff</button>}
          {user ? <button className="staff-button" onClick={logout}>Sign out</button> : <button className="staff-button" onClick={startGoogleSignIn}>Sign in with Google</button>}
        </div>
      </header>
      {(notice || error) && (
        <div
          className={`toast-notice ${error ? "error" : ""}`}
          role={error ? "alert" : "status"}
          aria-live={error ? "assertive" : "polite"}
        >
          <span>{error || notice}</span>
          <button
            type="button"
            aria-label="Dismiss notification"
            onClick={() => {
              setError("");
              setNotice("");
            }}
          >
            ×
          </button>
        </div>
      )}
      {showHome && (
        <>
          <section
            className={`hero hero-${featuredTheme.replaceAll("_", "-")}`}
            style={featuredMovie?.backdrop_url ? { "--hero-backdrop": `url("${featuredMovie.backdrop_url}")` } : undefined}
          >
            <MovieMagic key={`${featuredMovie?.id || featuredIndex}-${featuredTheme}`} theme={featuredTheme} />
            <div className="hero-content" aria-live="polite">
              <p>Tonight’s enchanted story</p>
              {featuredMovie && <MovieLifecycleLabel movie={featuredMovie} />}
              <h1><TitleWithAccent>{featuredMovie?.title || "Stories worth staying for."}</TitleWithAccent></h1>
              <span>{featuredMovie?.synopsis || "Step into an evening of wonder, adventure, and stories made for the big screen."}</span>
              <div className="hero-actions">
                <button className="primary" onClick={() => featuredMovie ? navigate(`/movies/${featuredMovie.slug}`) : navigate("/movies")}>
                  Discover this movie →
                </button>
                <button className="hero-all-movies" onClick={() => navigate("/movies")}>
                  View all movies
                </button>
              </div>
              {previews.length > 1 && (
                <div className="hero-switcher" aria-label="Featured movies">
                  <button className="hero-arrow" onClick={() => changeFeaturedMovie(-1)} aria-label="Previous featured movie">
                    ←
                  </button>
                  <div className="hero-dots">
                    {previews.map((item, index) => (
                      <button
                        key={item.id}
                        className={index === featuredIndex ? "active" : ""}
                        onClick={() => setFeaturedIndex(index)}
                        aria-label={`Show ${item.title}`}
                        aria-current={index === featuredIndex ? "true" : undefined}
                      />
                    ))}
                  </div>
                  <button className="hero-arrow" onClick={() => changeFeaturedMovie(1)} aria-label="Next featured movie">
                    →
                  </button>
                  <small>{String(featuredIndex + 1).padStart(2, "0")} / {String(previews.length).padStart(2, "0")}</small>
                </div>
              )}
            </div>
          </section>
          <section className="section">
            <div className="heading">
              <div>
                <p>The programme</p>
                <h2>Now showing & <em>arriving soon</em></h2>
              </div>
              <button className="text-link" onClick={() => navigate("/movies")}>
                See more movies →
              </button>
            </div>
            <div className="movie-grid">
              {previews.map((item) => (
                <MovieCard
                  key={item.id}
                  movie={item}
                  screenings={catalog.performances
                    .filter((s) => s.show_id === item.id)
                    .slice(0, 1)}
                  onMovie={() => navigate(`/movies/${item.slug}`)}
                  onScreen={(s) => openSeats(s, item)}
                />
              ))}
            </div>
          </section>
          <section className="section" id="cinema-rooms">
            <div className="heading">
              <div>
                <p>Inside Upon a Star</p>
                <h2>Choose your cinema <em>room.</em></h2>
              </div>
              <span>Each screening shows its exact room before you choose seats.</span>
            </div>
            <div className="room-guide">
              <article><b>Normal</b><p>Our everyday cinema room: 120 seats, a full-size screen, and the widest choice of showtimes.</p></article>
              <article><b>Premier</b><p>A smaller 36-seat room for a more intimate experience, premium sound, and selected special screenings.</p></article>
              <article><b>Small</b><p>A relaxed 60-seat room for documentaries, family films, and quieter showings.</p></article>
            </div>
          </section>
          <section className="section village-story">
            <div className="heading">
              <div>
                <p>The Upon a Star standard</p>
                <h2>Good films deserve a good <em>room.</em></h2>
              </div>
              <span>From the programme to the seat you choose, every detail is designed to make a night at the cinema feel considered.</span>
            </div>
            <div className="story-grid">
              <article><b>Thoughtfully selected</b><p>A changing mix of new releases, family favourites, documentaries, and independent stories—chosen for a programme with something worth discussing afterwards.</p></article>
              <article><b>Made for the big screen</b><p>Clear sound, comfortable seating, and room types that suit the film, whether it is a blockbuster, an intimate drama, or a weekend family outing.</p></article>
              <article><b>A warm local cinema</b><p>Easy booking, assigned seating, and a friendly team make the whole visit feel simple from the first showtime to the closing credits.</p></article>
            </div>
            <div className="guest-notes">
              <div><p>Guest notes</p><h3>“The room was comfortable, the seat picker was simple, and the film felt like a proper night out.”</h3><span>— Sample guest comment</span></div>
              <div><p>Guest notes</p><h3>“A lovely little cinema for taking a chance on a film you have not heard of yet.”</h3><span>— Sample guest comment</span></div>
              <small>Illustrative launch copy for this demo; replace with genuine reviews and awards when available.</small>
            </div>
          </section>
          <section className="section visit-card" id="visit-contact">
            <div>
              <p>Plan your visit</p>
              <h2>Everything for a smooth night <em>out.</em></h2>
              <span>Arrive a little early, collect snacks, and settle into your assigned seat before the trailers begin. Our cinema team can help with booking questions, accessibility requirements, and group visits.</span>
            </div>
            <div className="visit-details">
              <article><b>Opening hours</b><span>Daily · 10:00–22:30<br />Last entry follows the final scheduled screening.</span></article>
              <article><b>Need a hand?</b><span>For ticket changes, accessibility support, or group bookings, speak to the team at the cinema desk before your screening.</span></article>
              <article><b>Before you arrive</b><span>Keep your booking reference handy and arrive 20 minutes before showtime for the most relaxed start.</span></article>
            </div>
          </section>
          <section className="cinema-finale">
            <div className="finale-rings" aria-hidden="true" />
            <div className="finale-content">
              <b className="finale-mark"><svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true"><path d="M12 2 L14.3 9.7 L22 12 L14.3 14.3 L12 22 L9.7 14.3 L2 12 L9.7 9.7 Z" /></svg></b>
              <p>Every story starts somewhere</p>
              <h2>Your next film <em>is waiting.</em></h2>
              <button className="primary" onClick={() => navigate("/movies")}>Discover your next story →</button>
            </div>
          </section>
        </>
      )}
      {showMovies && (
        <section className="section">
          <div className="heading">
            <div>
              <p>The complete programme</p>
              <h1>All <em>movies</em></h1>
            </div>
            <span>
              Explore ongoing and arriving-soon films. Ticket sales open no more than one month before each movie starts.
            </span>
          </div>
          <div className="movie-grid">
            {movies.map((item) => (
              <MovieCard
                key={item.id}
                movie={item}
                screenings={catalog.performances
                  .filter((s) => s.show_id === item.id)
                  .slice(0, 1)}
                onMovie={() => navigate(`/movies/${item.slug}`)}
                onScreen={(s) => openSeats(s, item)}
              />
            ))}
          </div>
        </section>
      )}
      {showDetail && (
        <MovieDetail
          data={movie}
          onBack={() => navigate("/movies")}
          onScreen={openSeats}
          requiresSignIn={!user}
        />
      )}
      {showTickets && (
        <TicketChecker
          user={user}
          tickets={ticketResults}
          onSubmit={findTickets}
          onGoogleSignIn={startGoogleSignIn}
        />
      )}
      {showProfile && <ProfilePage user={user} csrf={csrf} onSaved={(updatedUser) => { setUser(updatedUser); setNotice("Profile saved."); navigate("/"); }} />}
      {showStaff && (
        <StaffArea
          user={user}
          bookings={bookings}
          rooms={ownerData.rooms}
          movies={ownerData.movies}
          accounts={ownerData.accounts || []}
          screenings={ownerData.screenings || []}
          summary={ownerData.summary}
          nextScreening={ownerData.nextScreening}
          csrf={csrf}
          onMovieCreated={(createdMovie) => setOwnerData((current) => ({
            ...current,
            movies: [createdMovie, ...(current.movies || [])],
          }))}
          onScreeningCreated={(createdScreening) => setOwnerData((current) => ({
            ...current,
            screenings: [createdScreening, ...(current.screenings || [])]
              .sort((first, second) => new Date(second.starts_at) - new Date(first.starts_at))
              .slice(0, 250),
          }))}
          onRefresh={() => {
            window.sessionStorage.setItem("silver-staff-scroll-y", String(window.scrollY));
            window.location.reload();
          }}
        />
      )}
      <footer className="cinema-footer">
        <div className="footer-main">
          <div className="footer-intro">
            <button className="footer-brand" onClick={() => navigate("/")}>
              <b><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M12 2 L14.3 9.7 L22 12 L14.3 14.3 L12 22 L9.7 14.3 L2 12 L9.7 9.7 Z" /></svg></b>
              <span><strong>Upon a <em>Star</em></strong><small>MOVIES UNDER THE STARS</small></span>
            </button>
            <p>One cinema, many worlds. Discover thoughtful films, choose your screening, and settle in for a story worth remembering.</p>
          </div>
          <div className="footer-column">
            <p>Explore</p>
            <button onClick={() => navigate("/movies")}>Now showing</button>
            <button onClick={() => navigate("/tickets")}>My tickets</button>
            <button onClick={() => goToSection("cinema-rooms")}>Cinema rooms</button>
          </div>
          <div className="footer-column">
            <p>Visit</p>
            <span>Open daily · 10:00–22:30</span>
            <span>Arrive 20 minutes before showtime.</span>
            <button onClick={() => goToSection("visit-contact")}>Plan your visit</button>
          </div>
          <div className="footer-column">
            <p>Access</p>
            <span>Assigned seating and accessible booking support are available.</span>
            <span>Speak to the cinema team for venue assistance.</span>
          </div>
        </div>
        <div className="footer-base">
          <small>© {new Date().getFullYear()} Upon a Star Cinema</small>
          <span>Original cinema concept · Movies under the stars</span>
        </div>
      </footer>
      {seatPicker && (
        <SeatPicker
          data={seatPicker}
          onClose={() => setSeatPicker(null)}
          onToggle={toggleSeat}
          onAdd={addToCart}
        />
      )}
      {cartOpen && (
        <Cart
          cart={cart}
          total={cartTotal}
          user={user}
          form={form}
          setForm={setForm}
          result={result}
          onClose={() => {
            setCartOpen(false);
            setResult(null);
          }}
          onCheckout={checkout}
          onRemove={(id) =>
            setCart((items) => items.filter((item) => item.screening.id !== id))
          }
        />
      )}
    </div>
  );
}
function MovieCard({ movie, screenings, onMovie, onScreen }) {
  return (
    <article className="movie-card">
      <button className="movie-poster" onClick={onMovie} aria-label={`Open ${movie.title}`}>
        <img src={movie.backdrop_url || movie.poster_url} alt={`${movie.title} cinematic artwork`} />
      </button>
      <div>
        <MovieLifecycleLabel movie={movie} />
        <div className="tags">
          <span>{movie.genre}</span>
          <span>{movie.age_rating}</span>
        </div>
        <button className="movie-title" onClick={onMovie}>
          <TitleWithAccent>{movie.title}</TitleWithAccent>
        </button>
        <p>{movie.synopsis}</p>
        <small>{movie.duration_minutes} minutes</small>
        {screenings.map((screening) => (
          <button
            className="showtime"
            key={screening.id}
            onClick={() => Number(screening.booking_open) && onScreen(screening, movie)}
            disabled={!Number(screening.booking_open)}
          >
            <b>{dateTime(screening.starts_at)}</b>
            <span>
              {screening.room_name} · {screening.room_type} ·{" "}
              {money(screening.unit_price)}
            </span>
            {!Number(screening.booking_open) && <small>Tickets available {movieDateLabel(movie.sales_open_date)}</small>}
          </button>
        ))}
        <button className="text-link" onClick={onMovie}>
          View movie and showtimes →
        </button>
      </div>
    </article>
  );
}
function MovieDetail({ data, onBack, onScreen, requiresSignIn }) {
  const screenings = data?.screenings || [];
  const dates = [...new Set(screenings.map((screening) => screeningDate(screening.starts_at)))];
  const [selectedDate, setSelectedDate] = useState(dates[0] || "");
  useEffect(() => setSelectedDate(dates[0] || ""), [data?.movie?.id]);
  const selectedScreenings = screenings.filter(
    (screening) => screeningDate(screening.starts_at) === selectedDate,
  );
  if (!data) return <section className="section">Loading movie…</section>;
  const { movie } = data;
  return (
    <section className="movie-detail">
      <button className="text-link" onClick={onBack}>
        ← All movies
      </button>
      <div className="detail-grid">
        <img src={movie.poster_url} alt="" />
        <div>
          <p>
            {movie.genre} · {movie.age_rating}
          </p>
          <h1><TitleWithAccent>{movie.title}</TitleWithAccent></h1>
          <MovieLifecycleLabel movie={movie} />
          <span>{movie.synopsis}</span>
          <small>{movie.duration_minutes} minutes</small>
          {movie.trailer_url && (
            <a className="text-link" href={movie.trailer_url} target="_blank">
              Watch trailer →
            </a>
          )}
          <h2>Choose a screening</h2>
          {!Number(movie.tickets_on_sale) && <p className="sign-in-seat-notice"><b>Tickets open {movieDateLabel(movie.sales_open_date)}.</b> You can explore the scheduled dates now and return when pre-sale begins.</p>}
          {Boolean(Number(movie.tickets_on_sale)) && requiresSignIn && <p className="sign-in-seat-notice"><b>Sign in with Google to reserve seats.</b> We hold selected seats for 10 minutes, then return you here to continue.</p>}
          {dates.length ? (
            <>
              <p className="screening-help">Select a date to see every available time.</p>
              <div className="date-filter" aria-label="Screening dates">
                {dates.map((date) => (
                  <button
                    key={date}
                    className={date === selectedDate ? "active" : ""}
                    onClick={() => setSelectedDate(date)}
                  >
                    {screeningDateLabel(date)}
                  </button>
                ))}
              </div>
            </>
          ) : (
            <p className="screening-help">No upcoming screenings have been scheduled yet.</p>
          )}
          <div className="screening-list">
            {selectedScreenings.map((screening) => (
              <button
                className="showtime"
                key={screening.id}
                onClick={() => Number(screening.booking_open) && onScreen(screening, movie)}
                disabled={!Number(screening.booking_open)}
              >
                <b>{Number(screening.booking_open) && requiresSignIn ? `Sign in to reserve · ${dateTime(screening.starts_at)}` : dateTime(screening.starts_at)}</b>
                <span>
                  {screening.room_name} · {screening.room_type} ·{" "}
                  {screening.seats_available} seats left ·{" "}
                  {money(screening.unit_price)}
                </span>
                {!Number(screening.booking_open) && <small>Pre-sale opens {movieDateLabel(movie.sales_open_date)}</small>}
              </button>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
function ProfilePage({ user, csrf, onSaved }) {
  const [displayName, setDisplayName] = useState(user?.displayName || "");
  const [dateOfBirth, setDateOfBirth] = useState(user?.dateOfBirth ? String(user.dateOfBirth).slice(0, 10) : "");
  const [saving, setSaving] = useState(false);
  if (!user) return <section className="section"><h1>Sign in required</h1><p>Please sign in with Google to manage your profile.</p></section>;
  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    try {
      const data = await fetch(`${API}/profile`, { method: "PUT", credentials: "include", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ displayName, dateOfBirth }) }).then(json);
      onSaved(data.user);
    } finally { setSaving(false); }
  };
  return <section className="section profile-page"><p>{user.onboardingComplete ? "Your account" : "One last step"}</p><h1>{user.onboardingComplete ? "Your profile" : "Tell us about you."}</h1><span>{user.onboardingComplete ? "Update the details on your Upon a Star account." : "We have your Google email. Please confirm your name and date of birth before continuing."}</span><form onSubmit={save} className="profile-form"><label>Name<input value={displayName} onChange={(e) => setDisplayName(e.target.value)} required /></label><label>Date of birth<input type="date" value={dateOfBirth} onChange={(e) => setDateOfBirth(e.target.value)} required max={new Date().toISOString().slice(0, 10)} /></label><label>Google email<input value={user.email || ""} disabled /></label><button className="primary" disabled={saving}>{saving ? "Saving…" : "Save profile"}</button></form></section>;
}
function TicketChecker({ user, tickets, onSubmit, onGoogleSignIn }) {
  return (
    <section className="section ticket-checker">
      <p>Find a booking</p>
      <h1>Your tickets</h1>
      {user ? (
        <>
          <span>Signed in as {user.displayName} ({user.email}). Active tickets show a QR code; cancelled or finished tickets remain listed only as history.</span>
          <div className="ticket-lookup-form"><button className="primary" onClick={onSubmit}>Show my tickets</button></div>
          {tickets && (tickets.length ? (
            <div className="ticket-results">{tickets.map((ticket) => {
              const hasAvailableTicket = ticket.items.some((item) => Number(item.ticketAvailable));
              return (
                <article key={ticket.reference}>
                  <p>Booking reference</p>
                  <h2>{ticket.reference}</h2>
                  {ticket.items.map((item) => {
                    const available = Number(item.ticketAvailable);
                    return (
                      <div className={available ? "ticket-screening" : "ticket-screening unavailable"} key={`${ticket.reference}-${item.title}-${item.startsAt}`}>
                        <b>{item.title}</b>
                        <span>{dateTime(item.startsAt)} · {item.roomName}</span>
                        {!available && <strong className="ticket-unavailable-reason">Unavailable · {item.ticketUnavailableReason || "Movie or screening no longer available"}</strong>}
                        <div className="customer-ticket-codes">{item.seats.map((seat) => (
                          <div key={seat.ticketCode} className={!available ? "customer-ticket unavailable" : seat.checkedInAt ? "used-ticket" : "customer-ticket"}>
                            {available ? <TicketQr value={seat.ticketCode} /> : <div className="unavailable-ticket-mark" aria-hidden="true">×</div>}
                            <span><b>Seat {seat.code}</b>{available && <small>{seat.ticketCode}</small>}<em>{!available ? "Ticket no longer valid" : seat.checkedInAt ? "Already checked in" : "Present this QR at entry"}</em></span>
                          </div>
                        ))}</div>
                      </div>
                    );
                  })}
                  {hasAvailableTicket && <button className="print-ticket" onClick={() => window.print()}>Print active ticket</button>}
                </article>
              );
            })}</div>
          ) : <div className="empty">No confirmed tickets were found for this Google account.</div>)}
        </>
      ) : <div className="ticket-lookup-form"><span>Sign in with the Google account used for your booking to see your confirmed tickets.</span><button className="primary" onClick={onGoogleSignIn}>Continue with Google</button></div>}
    </section>
  );
}
function SeatPicker({ data, onClose, onToggle, onAdd }) {
  const rows = useMemo(
    () =>
      data.seats.reduce(
        (all, seat) => ({
          ...all,
          [seat.row_label]: [...(all[seat.row_label] || []), seat],
        }),
        {},
      ),
    [data.seats],
  );
  return (
    <div className="modal-bg">
      <section className="seat-modal">
        <button className="close" onClick={onClose}>
          ×
        </button>
        <div className="seat-info">
          <img src={data.movie.poster_url} alt="" />
          <p>{data.screening.room_type} room</p>
          <h2>{data.screening.title}</h2>
          <span>{data.screening.synopsis}</span>
          <small>
            {dateTime(data.screening.starts_at)} · {data.screening.room_name} ·{" "}
            {money(data.screening.unit_price)} each
          </small>
          <strong>
            {data.selected.length
              ? `${data.selected.length} seat${data.selected.length > 1 ? "s" : ""} selected`
              : "Choose your seats"}
          </strong>
          <HoldCountdown expiresAt={data.holdExpiresAt} />
        </div>
        <div className="seat-map">
          <div className="screen">SCREEN</div>
          <p>
            <i className="available" /> Available <i className="selected" />{" "}
            Selected <i className="taken" /> Taken
          </p>
          {Object.entries(rows).map(([row, seats]) => (
            <div className="seat-row" key={row}>
              <b>{row}</b>
              {[1, 2, 3].map((block) => (
                <div className={`seat-block block-${block}`} key={block}>
                  {seats
                    .filter((seat) => Number(seat.block_number) === block)
                    .map((seat) => (
                      <button
                        key={seat.id}
                        disabled={!seat.available}
                        aria-label={`Seat ${seat.seat_code}, ${seat.available ? (data.selected.includes(seat.id) ? "selected" : "available") : "taken"}`}
                        className={`${seat.available ? "available" : "taken"} ${data.selected.includes(seat.id) ? "selected" : ""}`}
                        onClick={() => onToggle(seat.id)}
                      >
                        {seat.seat_number}
                      </button>
                    ))}
                </div>
              ))}
            </div>
          ))}
          <button className="primary" onClick={onAdd}>
            Add seats to cart ·{" "}
            {money(Number(data.screening.unit_price) * data.selected.length)}
          </button>
        </div>
      </section>
    </div>
  );
}
function Cart({
  cart,
  total,
  user,
  form,
  setForm,
  result,
  onClose,
  onCheckout,
  onRemove,
}) {
  return (
    <div className="drawer-bg">
      <aside className="cart">
        <button className="close" onClick={onClose}>
          ×
        </button>
        {result ? (
          <div className="success">
            <p>Booking confirmed</p>
            <h2>Seats booked</h2>
            <strong>{result.reference}</strong>
            {result.items.map((item) => (
              <p key={item.title}>
                {item.title} · {item.roomName} · {item.seats.join(", ")}
              </p>
            ))}
            <b>Booking total: {money(result.total)}</b>
            <p>Each seat has a QR code in My bookings. Show each code at the cinema entrance.</p>
            <a className="primary" href={`${PHP_SITE}/my-bookings.php`}>Open My bookings →</a>
          </div>
        ) : (
          <>
            <p>Your tickets</p>
            <h2>Review your night.</h2>
            {cart.length ? (
              <>
                <HoldCountdown
                  expiresAt={cart.map((item) => item.holdExpiresAt).filter(Boolean).sort()[0]}
                  label="Complete checkout within"
                />
                <div className="cart-lines">
                  {cart.map((item) => (
                    <article key={item.screening.id}>
                      <div>
                        <strong>{item.movie.title}</strong>
                        <small>
                          {dateTime(item.screening.starts_at)} ·{" "}
                          {item.screening.room_name}
                        </small>
                        <span>
                          Seats:{" "}
                          {item.seats.map((seat) => seat.seat_code).join(", ")}
                        </span>
                      </div>
                      <b>
                        {money(
                          Number(item.screening.unit_price) *
                            item.seatIds.length,
                        )}
                      </b>
                      <button onClick={() => onRemove(item.screening.id)}>
                        Remove
                      </button>
                    </article>
                  ))}
                </div>
                <div className="total">
                  <span>Total</span>
                  <b>{money(total)}</b>
                </div>
                <form onSubmit={onCheckout}>
                  <p>This reservation is linked to {user?.displayName} ({user?.email}).</p>
                  <input
                    placeholder="Phone number"
                    required
                    value={form.customerPhone}
                    onChange={(e) =>
                      setForm({ ...form, customerPhone: e.target.value })
                    }
                  />
                  <p className="no-refund-notice">This site does not process payments. Each booked seat gets an entry QR code immediately.</p>
                  <button className="primary">Reserve seats →</button>
                  <small>
                    Your seat QR codes will appear in My bookings.
                  </small>
                </form>
              </>
            ) : (
              <div className="empty">
                Choose a screening and seats to begin.
              </div>
            )}
          </>
        )}
      </aside>
    </div>
  );
}
function MovieLifecycleEditor({ movie, onSave }) {
  const [dates, setDates] = useState({
    runStartDate: movieDateValue(movie.run_start_date),
    runEndDate: movieDateValue(movie.run_end_date),
    salesOpenDate: movieDateValue(movie.sales_open_date),
    themeKey: movieMagicTheme(movie),
  });
  useEffect(() => setDates({
    runStartDate: movieDateValue(movie.run_start_date),
    runEndDate: movieDateValue(movie.run_end_date),
    salesOpenDate: movieDateValue(movie.sales_open_date),
    themeKey: movieMagicTheme(movie),
  }), [movie.id, movie.run_start_date, movie.run_end_date, movie.sales_open_date, movie.theme_key]);
  const lifecycleText = movie.status === "archived"
    ? "Archived · owner only"
    : movie.lifecycle_status === "arriving_soon"
    ? "Arriving soon"
    : movie.lifecycle_status === "ended"
      ? "Ended · owner only"
      : "Ongoing";
  return (
    <article className="movie-lifecycle-editor">
      <div className="movie-admin-heading">
        <b>{movie.title}</b>
        <span>{movie.status === "archived" ? lifecycleText : `${movie.status} · ${lifecycleText}`}</span>
      </div>
      <MovieThemeSelect compact value={dates.themeKey} onChange={(themeKey) => setDates({ ...dates, themeKey })} />
      <div className="movie-date-fields">
        <label>
          Starts
          <input type="date" value={dates.runStartDate} onInput={(e) => setDates({ ...dates, runStartDate: e.target.value })} />
        </label>
        <label>
          Ends
          <input type="date" min={dates.runStartDate || undefined} value={dates.runEndDate} onInput={(e) => setDates({ ...dates, runEndDate: e.target.value })} />
        </label>
        <label>
          Sales open
          <input type="date" max={dates.runStartDate || undefined} value={dates.salesOpenDate} onInput={(e) => setDates({ ...dates, salesOpenDate: e.target.value })} />
        </label>
      </div>
      <div className="account-actions">
        <button onClick={() => onSave(movie.id, dates)} disabled={!dates.runStartDate || !dates.runEndDate}>Save movie settings</button>
        {(movie.status === "draft" || movie.status === "archived") && <button onClick={() => onSave(movie.id, { status: "published" })}>{movie.status === "archived" ? "Republish" : "Publish"}</button>}
        {movie.status !== "archived" && <button onClick={() => onSave(movie.id, { status: "archived" })}>Archive</button>}
      </div>
    </article>
  );
}
const SCREENINGS_PER_PAGE = 10;
function ScreeningManager({ screenings, movies, onUpdate, onDeleteHistory, onMovieSave }) {
  const [page, setPage] = useState(() => {
    const savedPage = Number(window.sessionStorage.getItem("silver-screening-page"));
    return Number.isInteger(savedPage) && savedPage > 0 ? savedPage : 1;
  });
  const [selectedPastIds, setSelectedPastIds] = useState([]);
  const [deletingHistory, setDeletingHistory] = useState(false);
  const totalPages = Math.max(1, Math.ceil(screenings.length / SCREENINGS_PER_PAGE));
  const currentPage = Math.min(page, totalPages);
  const pageStart = (currentPage - 1) * SCREENINGS_PER_PAGE;
  const pageScreenings = screenings.slice(pageStart, pageStart + SCREENINGS_PER_PAGE);
  const pastIdsOnPage = pageScreenings.filter((screening) => Number(screening.is_completed)).map((screening) => screening.id);
  const allPastOnPageSelected = pastIdsOnPage.length > 0 && pastIdsOnPage.every((id) => selectedPastIds.includes(id));
  useEffect(() => {
    if (page !== currentPage) setPage(currentPage);
    window.sessionStorage.setItem("silver-screening-page", String(currentPage));
  }, [currentPage, page]);
  useEffect(() => {
    const availablePastIds = new Set(screenings.filter((screening) => Number(screening.is_completed)).map((screening) => screening.id));
    setSelectedPastIds((current) => current.filter((id) => availablePastIds.has(id)));
  }, [screenings]);
  const togglePastScreening = (id) => setSelectedPastIds((current) => current.includes(id) ? current.filter((selectedId) => selectedId !== id) : [...current, id]);
  const togglePage = () => setSelectedPastIds((current) => {
    if (allPastOnPageSelected) return current.filter((id) => !pastIdsOnPage.includes(id));
    return [...new Set([...current, ...pastIdsOnPage])];
  });
  const deleteSelected = async () => {
    if (!selectedPastIds.length) return;
    const label = selectedPastIds.length === 1 ? "this past screening" : `these ${selectedPastIds.length} past screenings`;
    if (!window.confirm(`Permanently delete ${label} from the database?\n\nAll tickets attached to the selected screening${selectedPastIds.length === 1 ? "" : "s"} will also be deleted. Any booking left with no tickets, including its payment and email-delivery records, will be removed.\n\nThis cannot be undone.`)) return;
    setDeletingHistory(true);
    await onDeleteHistory(selectedPastIds);
    setDeletingHistory(false);
  };
  return (
    <section className="screening-manager">
      <div className="heading">
        <div><p>Screening management</p><h2>Upcoming and past screenings</h2></div>
        <span>Showing 10 screenings per page. Past screenings can be selected for permanent deletion from the database.</span>
      </div>
      <div className="screening-history-tools">
        <div><b>Past screening history</b><span>Select completed screenings below. A confirmation popup appears every time; upcoming screenings cannot be deleted.</span></div>
        <button type="button" className="danger-action" disabled={!selectedPastIds.length || deletingHistory} onClick={deleteSelected}>{deletingHistory ? "Deleting…" : `Delete selected (${selectedPastIds.length})`}</button>
      </div>
      <div className="table-wrap">
        <table>
          <thead><tr><th className="screening-select"><input type="checkbox" aria-label="Select all past screenings on this page" checked={allPastOnPageSelected} disabled={!pastIdsOnPage.length} onChange={togglePage} /></th><th>When</th><th>Movie</th><th>Room</th><th>Sold</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>{pageScreenings.map((screening) => {
            const isPast = Number(screening.is_completed);
            return (
              <tr key={screening.id} className={isPast ? "past-screening" : undefined}>
                <td className="screening-select">{isPast ? <input type="checkbox" aria-label={`Select ${screening.title} at ${dateTime(screening.starts_at)}`} checked={selectedPastIds.includes(screening.id)} onChange={() => togglePastScreening(screening.id)} /> : <span title="Upcoming screenings are protected">—</span>}</td>
                <td><div className="screening-time-cell"><b>{dateTime(screening.starts_at)}</b><small>Ends {dateTime(screening.ends_at)}</small><small>Room ready {dateTime(screening.room_available_at)}</small></div></td>
                <td><div className="screening-movie-cell"><b>{screening.title}</b><small>{screening.format} · {screening.language}</small></div></td>
                <td>{screening.room_name}</td>
                <td>{screening.seats_booked} / {screening.capacity}</td>
                <td><span className={`screening-status ${isPast ? "past" : screening.status}`}>{isPast ? `Past · ${screening.status}` : screening.status}</span></td>
                <td>{isPast ? <small>History only</small> : <div className="account-actions">{screening.status === "scheduled" && <button onClick={() => onUpdate(screening.id, { status: "cancelled", cancellationReason: "Cancelled by cinema" })}>Cancel screening</button>}<button onClick={() => onUpdate(screening.id, { startsAt: new Date(screening.starts_at).toISOString(), unitPrice: screening.unit_price })}>Save current</button></div>}</td>
              </tr>
            );
          })}</tbody>
        </table>
      </div>
      <nav className="table-pagination" aria-label="Screening history pages">
        <span>{screenings.length ? `Showing ${pageStart + 1}–${Math.min(pageStart + SCREENINGS_PER_PAGE, screenings.length)} of ${screenings.length}` : "No screenings"}</span>
        <div><button type="button" disabled={currentPage === 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>← Previous</button><b>Page {currentPage} of {totalPages}</b><button type="button" disabled={currentPage === totalPages} onClick={() => setPage((current) => Math.min(totalPages, current + 1))}>Next →</button></div>
      </nav>
      <h3>Movie lifecycle</h3>
      <p className="screening-help">Ended movies disappear from customer pages automatically but remain here for owners and historical records.</p>
      <div className="movie-admin-list">{movies.map((movie) => <MovieLifecycleEditor key={movie.id} movie={movie} onSave={onMovieSave} />)}</div>
    </section>
  );
}
function StaffTicketChecker({ scanResult, lookup, setLookup, lookupResults, selectedBooking, onFind, onScan, onCheckIn }) {
  return (
    <section className="staff-checker">
      <div className="heading"><div><p>Ticket checking</p><h2>Entry scanner</h2></div><span><b>Staff only.</b> Tickets become invalid automatically when their screening is cancelled or finished, or when their movie is no longer available.</span></div>
      <StaffQrScanner onScan={onScan} />
      {scanResult && <div className={`scan-result ${scanResult.status}`}><b>{scanResult.status === "accepted" ? "Entry approved" : scanResult.status === "used" ? "Already used ticket" : "Ticket not accepted"}</b>{scanResult.ticket ? <span>{scanResult.ticket.customer_name} · {scanResult.ticket.title} · Seat {scanResult.ticket.seat_code} · {scanResult.ticket.room_name}</span> : <span>{scanResult.error}</span>}</div>}
      <form onSubmit={(event) => { event.preventDefault(); onFind(); }}><input placeholder="SV-… / SVT-… / customer@email.com" value={lookup} onChange={(event) => setLookup(event.target.value)} required /><button className="primary">Look up ticket</button><button type="button" className="secondary" onClick={() => onScan(lookup)} disabled={!lookup}>Process QR code</button></form>
      {selectedBooking && <article className="booking-detail"><p>Booking {selectedBooking.reference}</p><h2>{selectedBooking.customerName}</h2><span>{selectedBooking.customerEmail} · {selectedBooking.customerPhone || "No phone"}</span>{selectedBooking.items.map((item) => {
        const available = Number(item.ticketAvailable);
        return <div className="booking-item" key={`${item.title}-${item.startsAt}`}><b>{item.title}</b><span>{dateTime(item.startsAt)} · {item.roomName} · {item.screeningStatus}</span>{!available && <strong className="ticket-unavailable-reason">Unavailable · {item.ticketUnavailableReason || "Movie or screening no longer available"}</strong>}<div className="ticket-chips">{item.tickets.map((ticket) => <button key={ticket.id} className={ticket.checkedInAt ? "checked" : available ? "ticket-chip" : "ticket-chip unavailable"} disabled={Boolean(ticket.checkedInAt) || !available} onClick={() => onCheckIn(ticket.id)}>{ticket.seat} · {!available ? "Ticket unavailable" : ticket.checkedInAt ? "Already checked in" : "Manual check-in"}<small>{ticket.code}</small></button>)}</div></div>;
      })}</article>}
      {lookupResults.length > 1 && <p className="lookup-count">{lookupResults.length} bookings found. Choose another reference from Recent bookings for its full detail.</p>}
    </section>
  );
}
function StaffArea({ user, bookings, rooms, movies, accounts, screenings, summary, nextScreening, csrf, onMovieCreated, onScreeningCreated, onRefresh }) {
  const [movieForm, setMovieForm] = useState({
    title: "",
    slug: "",
    synopsis: "",
    genre: "",
    durationMinutes: 90,
    ageRating: "PG",
    posterUrl: "",
    backdropUrl: "",
    trailerUrl: "",
    runStartDate: "",
    runEndDate: "",
    salesOpenDate: "",
    themeKey: "enchanted_castle",
    status: "published",
    featured: false,
  });
  const [screening, setScreening] = useState({
    showId: "",
    roomId: "",
    startsAt: "",
    format: "Standard",
    language: "English",
    unitPrice: "",
  });
  const [message, setMessage] = useState("");
  const [lookup, setLookup] = useState("");
  const [lookupResults, setLookupResults] = useState([]);
  const [selectedBooking, setSelectedBooking] = useState(null);
  const [scanResult, setScanResult] = useState(null);
  const [savingPath, setSavingPath] = useState("");
  const send = async (path, body) => {
    setSavingPath(path);
    try {
      const data = await fetch(`${API}${path}`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify(body),
      }).then(json);
      if (data.movie) {
        onMovieCreated(data.movie);
        setMessage(`Saved movie · ${data.movie.title}.`);
      } else if (data.screening) {
        onScreeningCreated(data.screening);
        setMessage(`Scheduled · ${data.screening.title} · ${data.screening.room_code} · ${dateTime(data.screening.starts_at)}.`);
      } else {
        setMessage("Saved successfully.");
      }
      return data;
    } finally {
      setSavingPath("");
    }
  };
  const changeAccount = async (accountId, changes) => {
    try {
      await fetch(`${API}/admin/accounts/${accountId}`, { method: "PATCH", credentials: "include", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(changes) }).then(json);
      setMessage("Account updated.");
      onRefresh();
    } catch (error) { setMessage(error.message); }
  };
  const findBooking = async (value = lookup) => {
    try { const data = await fetch(`${API}/operations/ticket-lookup?q=${encodeURIComponent(value)}`, { credentials: "include" }).then(json); setLookupResults(data.bookings || []); setSelectedBooking(data.bookings?.[0] || null); if (!data.bookings?.length) setMessage("No matching booking found."); } catch (error) { setMessage(error.message); }
  };
  const openBooking = async (reference) => {
    try { const data = await fetch(`${API}/operations/bookings/${encodeURIComponent(reference)}`, { credentials: "include" }).then(json); setSelectedBooking(data.booking); } catch (error) { setMessage(error.message); }
  };
  const checkIn = async (ticketId) => {
    try { await fetch(`${API}/operations/tickets/${ticketId}/check-in`, { method: "PATCH", credentials: "include", headers: { "X-CSRF-Token": csrf } }).then(json); setMessage("Ticket checked in."); if (selectedBooking) openBooking(selectedBooking.reference); } catch (error) { setMessage(error.message); }
  };
  const scanTicket = async (code) => {
    setScanResult(null);
    try {
      const data = await fetch(`${API}/operations/scan-ticket`, { method: "POST", credentials: "include", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ code }) }).then(json);
      setScanResult(data);
      setLookup(data.ticket.ticket_code);
      findBooking(data.ticket.ticket_code);
    } catch (error) { setScanResult({ status: "invalid", error: error.message }); }
  };
  const updateScreening = async (id, changes) => {
    try { await fetch(`${API}/admin/screenings/${id}`, { method: "PATCH", credentials: "include", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(changes) }).then(json); setMessage("Screening updated."); onRefresh(); } catch (error) { setMessage(error.message); }
  };
  const deleteScreeningHistory = async (ids) => {
    try { const data = await fetch(`${API}/admin/screenings`, { method: "DELETE", credentials: "include", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ ids }) }).then(json); setMessage(`${data.deleted} past screening${data.deleted === 1 ? "" : "s"} permanently deleted with ${data.deletedTickets} attached ticket${data.deletedTickets === 1 ? "" : "s"}.`); onRefresh(); } catch (error) { setMessage(error.message); }
  };
  const updateMovie = async (id, changes) => {
    try { await fetch(`${API}/admin/movies/${id}`, { method: "PATCH", credentials: "include", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(changes) }).then(json); setMessage(changes.status === "archived" ? "Movie archived from customer pages." : "Movie lifecycle updated."); onRefresh(); } catch (error) { setMessage(error.message); }
  };
  if (!user)
    return (
      <section className="section">
        <h1>Staff sign-in required</h1>
      </section>
    );
  return (
    <section className="section staff">
      <p>Private operations</p>
      <h1>
        {user.role === "owner" ? "Cinema owner dashboard" : "Booking desk"}
      </h1>
      {message && <p className="notice">{message}</p>}
      {user.role === "owner" && summary && <section className="dashboard-summary"><article><span>Today’s screenings</span><b>{summary.screenings_today}</b></article><article><span>Tickets sold</span><b>{summary.tickets_sold_today}</b></article><article><span>Today’s revenue</span><b>{money(summary.revenue_today)}</b></article><article><span>Rooms in use</span><b>{summary.rooms_in_use_today}</b></article><article className="next-show"><span>Next screening</span><b>{nextScreening ? `${nextScreening.title} · ${dateTime(nextScreening.starts_at)}` : "None scheduled"}</b><small>{nextScreening?.room_name}</small></article></section>}
      {user.role === "owner" && (
        <div className="owner-grid">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              send("/admin/movies", movieForm).catch((err) =>
                setMessage(err.message),
              );
            }}
          >
            <h2>Create movie</h2>
            {[
              ["title", "Title"],
              ["slug", "URL slug"],
              ["genre", "Genre"],
              ["durationMinutes", "Duration (minutes)"],
              ["ageRating", "Age rating"],
              ["posterUrl", "Poster image URL"],
              ["backdropUrl", "Backdrop image URL"],
              ["trailerUrl", "Trailer URL"],
            ].map(([key, label]) => (
              <input
                key={key}
                type={key === "durationMinutes" ? "number" : "text"}
                placeholder={label}
                value={movieForm[key]}
                onChange={(e) =>
                  setMovieForm({ ...movieForm, [key]: e.target.value })
                }
                required={key !== "trailerUrl"}
              />
            ))}
            <textarea
              placeholder="Synopsis"
              value={movieForm.synopsis}
              onChange={(e) =>
                setMovieForm({ ...movieForm, synopsis: e.target.value })
              }
              required
            />
            <MovieThemeSelect value={movieForm.themeKey} onChange={(themeKey) => setMovieForm({ ...movieForm, themeKey })} />
            <div className="movie-date-fields">
              <label>
                Cinema run starts
                <input
                  type="text"
                  placeholder="Run start YYYY-MM-DD"
                  value={movieForm.runStartDate}
                  onChange={(e) => setMovieForm({ ...movieForm, runStartDate: e.target.value })}
                  required
                />
              </label>
              <label>
                Cinema run ends
                <input
                  type="text"
                  placeholder="Run end YYYY-MM-DD"
                  value={movieForm.runEndDate}
                  onChange={(e) => setMovieForm({ ...movieForm, runEndDate: e.target.value })}
                  required
                />
              </label>
              <label>
                Ticket sales open
                <input
                  type="text"
                  placeholder="Sales open YYYY-MM-DD"
                  value={movieForm.salesOpenDate}
                  onChange={(e) => setMovieForm({ ...movieForm, salesOpenDate: e.target.value })}
                />
              </label>
            </div>
            <small>Leave ticket sales blank to open exactly one calendar month before the movie starts. A later opening date is allowed; an earlier one is blocked.</small>
            <select
              value={movieForm.status}
              onChange={(e) =>
                setMovieForm({ ...movieForm, status: e.target.value })
              }
            >
              <option value="draft">Draft</option>
              <option value="published">Published</option>
            </select>
            <button className="primary" disabled={Boolean(savingPath)}>{savingPath === "/admin/movies" ? "Saving movie…" : "Save movie"}</button>
          </form>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              send("/admin/screenings", {
                ...screening,
                startsAt: new Date(screening.startsAt).toISOString(),
              }).catch((err) => setMessage(err.message));
            }}
          >
            <h2>Schedule screening</h2>
            <select
              value={screening.showId}
              onChange={(e) =>
                setScreening({ ...screening, showId: e.target.value })
              }
              required
            >
              <option value="">Choose movie</option>
              {movies.map((m) => (
                  <option value={m.id} key={m.id}>
                    {m.title}{m.status === "archived" ? " · archived" : ""} · {movieDateLabel(m.run_start_date)}–{movieDateLabel(m.run_end_date)}
                  </option>
                ))}
            </select>
            <select
              value={screening.roomId}
              onChange={(e) =>
                setScreening({ ...screening, roomId: e.target.value })
              }
              required
            >
              <option value="">Choose room</option>
              {rooms
                .filter((r) => r.active)
                .map((r) => (
                  <option value={r.id} key={r.id}>
                    {r.name} · {r.room_type}
                  </option>
                ))}
            </select>
            <input
              type="text"
              placeholder="Screening time YYYY-MM-DDTHH:mm"
              value={screening.startsAt}
              onChange={(e) =>
                setScreening({ ...screening, startsAt: e.target.value })
              }
              required
            />
            <select
              aria-label="Screening format"
              value={screening.format}
              onChange={(e) =>
                setScreening({ ...screening, format: e.target.value })
              }
              required
            >
              <option value="Standard">Standard — regular 2D</option>
              <option value="3D">3D</option>
              <option value="IMAX">IMAX</option>
              <option value="Dolby Atmos">Dolby Atmos</option>
            </select>
            <select
              aria-label="Spoken language"
              value={screening.language}
              onChange={(e) =>
                setScreening({ ...screening, language: e.target.value })
              }
              required
            >
              <option value="English">English</option>
              <option value="Bahasa Indonesia">Bahasa Indonesia</option>
              <option value="Mandarin">Mandarin</option>
              <option value="Japanese">Japanese</option>
              <option value="Korean">Korean</option>
              <option value="Other">Other</option>
            </select>
            <input
              type="number"
              placeholder="Ticket price"
              value={screening.unitPrice}
              onChange={(e) =>
                setScreening({ ...screening, unitPrice: e.target.value })
              }
              required
            />
            <button className="primary" disabled={Boolean(savingPath)}>{savingPath === "/admin/screenings" ? "Scheduling…" : "Allocate room"}</button>
          </form>
        </div>
      )}
      <StaffTicketChecker scanResult={scanResult} lookup={lookup} setLookup={setLookup} lookupResults={lookupResults} selectedBooking={selectedBooking} onFind={findBooking} onScan={scanTicket} onCheckIn={checkIn} />
      {user.role === "owner" && <ScreeningManager screenings={screenings} movies={movies} onUpdate={updateScreening} onDeleteHistory={deleteScreeningHistory} onMovieSave={updateMovie} />}
      {user.role === "owner" && <section className="account-directory"><div className="heading"><div><p>Google accounts</p><h2>Account access</h2></div><span>Owners manage the cinema. Staff can only search bookings and check tickets in. Disable immediately removes access.</span></div><div className="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Actions</th></tr></thead><tbody>{accounts.map((account) => <tr key={account.id}><td>{account.display_name}</td><td>{account.email}</td><td>{account.role}</td><td>{account.account_status}</td><td>{account.last_login_at ? dateTime(account.last_login_at) : "—"}</td><td>{account.id === user.id ? <small>Your account</small> : <div className="account-actions">{account.role === "customer" && <button onClick={() => changeAccount(account.id, { role: "staff" })}>Make staff</button>}{account.role === "staff" && <button onClick={() => changeAccount(account.id, { role: "customer" })}>Remove staff</button>}{account.role !== "owner" && <button onClick={() => changeAccount(account.id, { role: "owner" })}>Make owner</button>}{account.role === "owner" && <button onClick={() => changeAccount(account.id, { role: "customer" })}>Remove owner</button>}<button onClick={() => changeAccount(account.id, { accountStatus: account.account_status === "active" ? "disabled" : "active" })}>{account.account_status === "active" ? "Disable" : "Restore"}</button></div>}</td></tr>)}</tbody></table></div></section>}
      <h2>Recent bookings</h2>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Reference</th>
              <th>Customer</th>
              <th>Email</th>
              <th>Seats</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            {bookings.map((b) => (
              <tr key={b.id}>
                <td><button className="text-link" onClick={() => openBooking(b.public_reference)}>{b.public_reference}</button></td>
                <td>{b.customer_name}</td>
                <td>{b.customer_email}</td>
                <td>{b.seats || "—"}</td>
                <td>{money(b.total_amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
createRoot(document.getElementById("root")).render(<App />);
