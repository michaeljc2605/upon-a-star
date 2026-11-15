export const PROGRAMME_END_DATE = '2026-12-31';

const movie = (input) => ({
  status: 'published',
  featured: false,
  trailerUrl: '',
  runEndDate: PROGRAMME_END_DATE,
  posterUrl: `/assets/movies/${input.slug}/poster.webp`,
  backdropUrl: `/assets/movies/${input.slug}/backdrop.webp`,
  ...input,
});

export const movies = [
  movie({ title: 'Tangled', slug: 'tangled', synopsis: 'A spirited princess with magical hair escapes her hidden tower and discovers the floating lights with an unlikely guide.', genre: 'Animation, Family, Fantasy, Musical', durationMinutes: 100, ageRating: 'PG', themeKey: 'enchanted_castle', runStartDate: '2026-09-05', salesOpenDate: '2026-08-05', priorityRooms: ['P01', 'N01', 'N02'], sourceUrl: 'https://movies.disney.com/tangled' }),
  movie({ title: 'Lilo & Stitch', slug: 'lilo-and-stitch', synopsis: 'A lonely Hawaiian girl adopts a mischievous alien and teaches him that family means nobody gets left behind.', genre: 'Animation, Family, Science Fiction', durationMinutes: 92, ageRating: 'PG', themeKey: 'ocean_and_islands', runStartDate: '2026-09-05', salesOpenDate: '2026-08-05', priorityRooms: ['N03', 'N04', 'S01', 'S02'], sourceUrl: 'https://movies.disney.com/lilo-stitch' }),
  movie({ title: 'WALL-E', slug: 'wall-e', synopsis: 'A curious cleanup robot follows a sleek visitor into space and helps humanity rediscover its forgotten home.', genre: 'Animation, Family, Science Fiction', durationMinutes: 98, ageRating: 'G', themeKey: 'tech_and_arcade', runStartDate: '2026-09-05', salesOpenDate: '2026-08-05', priorityRooms: ['P02', 'N05', 'S03'], sourceUrl: 'https://movies.disney.com/wall-e' }),
  movie({ title: 'The Avengers', slug: 'the-avengers', synopsis: 'Earth’s mightiest heroes must learn to work as one team when an unexpected enemy threatens global security.', genre: 'Action, Adventure, Superhero', durationMinutes: 143, ageRating: 'PG-13', themeKey: 'hero_city', runStartDate: '2026-09-05', salesOpenDate: '2026-08-05', priorityRooms: ['P01', 'N06', 'N07', 'N08'], sourceUrl: 'https://www.marvel.com/movies/the-avengers', posterSource: 'https://image.tmdb.org/t/p/original/RYMX2wcKCBAr24UyPD7xwmjaTn.jpg', backdropSource: 'https://image.tmdb.org/t/p/original/9BBTo63ANSmhC4e6r62OJFuK2GL.jpg' }),
  movie({ title: 'Big Hero 6', slug: 'big-hero-6', synopsis: 'A young robotics prodigy joins his inflatable healthcare companion and a gifted group of friends to protect their city.', genre: 'Animation, Family, Science Fiction', durationMinutes: 102, ageRating: 'PG', themeKey: 'hero_city', runStartDate: '2026-09-05', salesOpenDate: '2026-08-05', priorityRooms: ['N09', 'S04', 'S05'], sourceUrl: 'https://movies.disney.com/big-hero-6' }),
  movie({ title: 'Frozen', slug: 'frozen', synopsis: 'A fearless princess crosses a frozen kingdom to find her sister and restore summer with courage, friendship, and love.', genre: 'Animation, Family, Fantasy, Musical', durationMinutes: 102, ageRating: 'PG', themeKey: 'legends_and_courage', runStartDate: '2026-09-05', salesOpenDate: '2026-08-05', priorityRooms: ['P02', 'N10', 'S06', 'S07', 'S08', 'S09', 'S10'], sourceUrl: 'https://movies.disney.com/frozen' }),
  movie({ title: 'Moana', slug: 'moana', synopsis: 'A determined island wayfinder sails beyond the reef with a legendary demigod to restore the heart of her people.', genre: 'Animation, Family, Adventure, Musical', durationMinutes: 107, ageRating: 'PG', themeKey: 'ocean_and_islands', runStartDate: '2026-09-26', salesOpenDate: '2026-08-26', priorityRooms: ['P01', 'N01', 'N02', 'S01'], anchorRoom: 'P01', sourceUrl: 'https://movies.disney.com/moana' }),
  movie({ title: 'Enchanted', slug: 'enchanted', synopsis: 'A fairytale princess is sent to modern New York, where storybook expectations meet complicated real-world love.', genre: 'Family, Fantasy, Comedy, Musical', durationMinutes: 107, ageRating: 'PG', themeKey: 'enchanted_castle', runStartDate: '2026-09-26', salesOpenDate: '2026-08-26', priorityRooms: ['P02', 'N03', 'N04', 'S02'], anchorRoom: 'P02', sourceUrl: 'https://movies.disney.com/enchanted' }),
  movie({ title: 'The Little Mermaid', slug: 'the-little-mermaid', synopsis: 'A curious mermaid princess makes a dangerous bargain for the chance to explore life and love beyond the sea.', genre: 'Animation, Family, Fantasy, Musical', durationMinutes: 82, ageRating: 'G', themeKey: 'ocean_and_islands', runStartDate: '2026-09-26', salesOpenDate: '2026-08-26', priorityRooms: ['N05', 'N06', 'S03', 'S04', 'S05'], anchorRoom: 'N05', sourceUrl: 'https://movies.disney.com/the-little-mermaid' }),
  movie({ title: 'Mulan', slug: 'mulan', synopsis: 'A courageous young woman disguises herself as a soldier and risks everything to protect her family and country.', genre: 'Animation, Family, Adventure, Musical', durationMinutes: 87, ageRating: 'G', themeKey: 'legends_and_courage', runStartDate: '2026-09-26', salesOpenDate: '2026-08-26', priorityRooms: ['P01', 'N07', 'N08', 'S06', 'S07'], anchorRoom: 'N07', sourceUrl: 'https://movies.disney.com/mulan' }),
  movie({ title: 'Brave', slug: 'brave', synopsis: 'A headstrong Scottish princess challenges an ancient tradition and must mend the magical consequences with bravery.', genre: 'Animation, Family, Adventure, Fantasy', durationMinutes: 93, ageRating: 'PG', themeKey: 'legends_and_courage', runStartDate: '2026-09-26', salesOpenDate: '2026-08-26', priorityRooms: ['P02', 'N09', 'N10', 'S08', 'S09', 'S10'], anchorRoom: 'N09', sourceUrl: 'https://movies.disney.com/brave' }),
  movie({ title: 'Snow White and the Seven Dwarfs', slug: 'snow-white-and-the-seven-dwarfs', synopsis: 'A kind princess finds friendship with seven miners while escaping the jealousy and magic of an evil queen.', genre: 'Animation, Family, Fantasy, Musical', durationMinutes: 83, ageRating: 'G', themeKey: 'enchanted_castle', runStartDate: '2026-10-17', salesOpenDate: '2026-09-17', priorityRooms: ['N01', 'S01', 'S02', 'S03'], anchorRoom: 'N01', sourceUrl: 'https://movies.disney.com/snow-white-and-the-seven-dwarfs' }),
  movie({ title: 'Cinderella', slug: 'cinderella', synopsis: 'With courage, kindness, and a little magic, a mistreated young woman finds her way to a life she chooses.', genre: 'Animation, Family, Fantasy, Musical', durationMinutes: 74, ageRating: 'G', themeKey: 'enchanted_castle', runStartDate: '2026-10-17', salesOpenDate: '2026-09-17', priorityRooms: ['N02', 'N03', 'S04', 'S05'], anchorRoom: 'N02', sourceUrl: 'https://movies.disney.com/cinderella-1950' }),
  movie({ title: 'The Lion King', slug: 'the-lion-king', synopsis: 'A young lion prince must face his past, accept his place, and restore balance to the Pride Lands.', genre: 'Animation, Family, Adventure, Musical', durationMinutes: 94, ageRating: 'G', themeKey: 'animal_worlds', runStartDate: '2026-10-17', salesOpenDate: '2026-09-17', priorityRooms: ['P02', 'N04', 'N05', 'N06'], anchorRoom: 'P02', sourceUrl: 'https://movies.disney.com/the-lion-king' }),
  movie({ title: 'Finding Nemo', slug: 'finding-nemo', synopsis: 'An anxious clownfish crosses the ocean with an unforgettable companion to rescue his adventurous young son.', genre: 'Animation, Family, Adventure, Comedy', durationMinutes: 100, ageRating: 'G', themeKey: 'ocean_and_islands', runStartDate: '2026-10-17', salesOpenDate: '2026-09-17', priorityRooms: ['P01', 'N07', 'S06', 'S07'], anchorRoom: 'P01', sourceUrl: 'https://movies.disney.com/finding-nemo' }),
  movie({ title: 'Cars', slug: 'cars', synopsis: 'A famous rookie race car gets stranded in a small town and discovers that life is about more than trophies.', genre: 'Animation, Family, Comedy, Sports', durationMinutes: 118, ageRating: 'G', themeKey: 'tech_and_arcade', runStartDate: '2026-10-17', salesOpenDate: '2026-09-17', priorityRooms: ['P01', 'N08', 'N09', 'S08'], anchorRoom: 'N08', sourceUrl: 'https://movies.disney.com/cars' }),
  movie({ title: 'Zootopia', slug: 'zootopia', synopsis: 'An optimistic rookie officer and a clever fox uncover a mystery that threatens their modern animal metropolis.', genre: 'Animation, Family, Adventure, Comedy', durationMinutes: 108, ageRating: 'PG', themeKey: 'animal_worlds', runStartDate: '2026-10-17', salesOpenDate: '2026-09-17', priorityRooms: ['P02', 'N10', 'S09', 'S10'], anchorRoom: 'N10', sourceUrl: 'https://movies.disney.com/zootopia' }),
  movie({ title: 'Toy Story', slug: 'toy-story', synopsis: 'A loyal cowboy toy faces jealousy and change when a confident space ranger becomes the room’s new favourite.', genre: 'Animation, Family, Adventure, Comedy', durationMinutes: 81, ageRating: 'G', themeKey: 'worlds_of_imagination', runStartDate: '2026-11-07', salesOpenDate: '2026-10-07', priorityRooms: ['N01', 'N02', 'S01', 'S02', 'S03'], anchorRoom: 'N01', sourceUrl: 'https://movies.disney.com/toy-story' }),
  movie({ title: 'Beauty and the Beast', slug: 'beauty-and-the-beast', synopsis: 'A thoughtful young woman discovers the humanity within an enchanted castle and its fearsome master.', genre: 'Animation, Family, Fantasy, Musical', durationMinutes: 84, ageRating: 'G', themeKey: 'enchanted_castle', runStartDate: '2026-11-07', salesOpenDate: '2026-10-07', priorityRooms: ['P02', 'N03', 'N04', 'S04'], anchorRoom: 'P02', sourceUrl: 'https://movies.disney.com/beauty-and-the-beast' }),
  movie({ title: 'Peter Pan', slug: 'peter-pan', synopsis: 'Three siblings fly beyond the stars to Never Land for an adventure with Peter Pan, fairies, and pirates.', genre: 'Animation, Family, Adventure, Fantasy', durationMinutes: 77, ageRating: 'G', themeKey: 'sky_adventure', runStartDate: '2026-11-07', salesOpenDate: '2026-10-07', priorityRooms: ['N05', 'N06', 'S05', 'S06', 'S07'], anchorRoom: 'N05', sourceUrl: 'https://movies.disney.com/peter-pan' }),
  movie({ title: 'Wreck-It Ralph', slug: 'wreck-it-ralph', synopsis: 'A misunderstood arcade villain leaves his game to prove he can be a hero and changes a digital world.', genre: 'Animation, Family, Adventure, Comedy', durationMinutes: 101, ageRating: 'PG', themeKey: 'tech_and_arcade', runStartDate: '2026-11-07', salesOpenDate: '2026-10-07', priorityRooms: ['P01', 'N07', 'N08', 'S08'], anchorRoom: 'P01', sourceUrl: 'https://movies.disney.com/wreck-it-ralph' }),
  movie({ title: 'Inside Out', slug: 'inside-out', synopsis: 'Five emotions work together inside a young girl’s mind as her family moves and her inner world changes.', genre: 'Animation, Family, Comedy, Drama', durationMinutes: 95, ageRating: 'PG', themeKey: 'worlds_of_imagination', runStartDate: '2026-11-07', salesOpenDate: '2026-10-07', priorityRooms: ['P02', 'N09', 'N10', 'S09', 'S10'], anchorRoom: 'N09', sourceUrl: 'https://movies.disney.com/inside-out' }),
  movie({ title: 'Monsters University', slug: 'monsters-university', synopsis: 'Two very different young monsters learn that talent, teamwork, and friendship matter more than first impressions.', genre: 'Animation, Family, Comedy', durationMinutes: 104, ageRating: 'G', themeKey: 'worlds_of_imagination', runStartDate: '2026-12-12', salesOpenDate: '2026-11-12', priorityRooms: ['N01', 'N02', 'S01', 'S02', 'S03'], anchorRoom: 'N01', sourceUrl: 'https://movies.disney.com/monsters-university' }),
  movie({ title: 'Up', slug: 'up', synopsis: 'An elderly dreamer lifts his home into the sky and discovers friendship, danger, and purpose on a wild journey.', genre: 'Animation, Family, Adventure, Comedy', durationMinutes: 96, ageRating: 'PG', themeKey: 'sky_adventure', runStartDate: '2026-12-12', salesOpenDate: '2026-11-12', priorityRooms: ['P02', 'N03', 'N04', 'S04'], anchorRoom: 'P02', sourceUrl: 'https://movies.disney.com/up' }),
  movie({ title: 'Ratatouille', slug: 'ratatouille', synopsis: 'A gifted rat with a passion for cooking forms an unlikely partnership inside a celebrated Paris restaurant.', genre: 'Animation, Family, Comedy', durationMinutes: 111, ageRating: 'G', themeKey: 'culinary_magic', runStartDate: '2026-12-12', salesOpenDate: '2026-11-12', priorityRooms: ['N05', 'N06', 'S05', 'S06'], anchorRoom: 'N05', sourceUrl: 'https://movies.disney.com/ratatouille' }),
  movie({ title: 'The Princess and the Frog', slug: 'the-princess-and-the-frog', synopsis: 'An ambitious cook is swept into a magical New Orleans adventure after a prince’s spell changes everything.', genre: 'Animation, Family, Fantasy, Musical', durationMinutes: 97, ageRating: 'G', themeKey: 'culinary_magic', runStartDate: '2026-12-12', salesOpenDate: '2026-11-12', priorityRooms: ['P02', 'N07', 'N08', 'S07', 'S08'], anchorRoom: 'N07', sourceUrl: 'https://movies.disney.com/the-princess-and-the-frog' }),
  movie({ title: 'The Incredibles', slug: 'the-incredibles', synopsis: 'A retired superhero family returns to action and learns that their greatest strength comes from working together.', genre: 'Animation, Family, Action, Superhero', durationMinutes: 115, ageRating: 'PG', themeKey: 'hero_city', runStartDate: '2026-12-12', salesOpenDate: '2026-11-12', priorityRooms: ['P01', 'N09', 'N10', 'S09', 'S10'], anchorRoom: 'P01', sourceUrl: 'https://movies.disney.com/the-incredibles' }),
];

const movieByTitle = new Map(movies.map((item) => [item.title, item]));
const fixedOpeningTitles = {
  N01: 'Tangled', N02: 'Tangled', N03: 'Lilo & Stitch', N04: 'Lilo & Stitch', N05: 'WALL-E',
  N06: 'The Avengers', N07: 'The Avengers', N08: 'The Avengers', N09: 'Big Hero 6', N10: 'Frozen',
  S01: 'Lilo & Stitch', S02: 'Lilo & Stitch', S03: 'WALL-E', S04: 'Big Hero 6', S05: 'Big Hero 6',
  S06: 'Frozen', S07: 'Frozen', S08: 'Frozen', S09: 'Frozen', S10: 'Frozen',
};
const allRooms = ['P01', 'P02', ...Array.from({ length: 10 }, (_, index) => `N${String(index + 1).padStart(2, '0')}`), ...Array.from({ length: 10 }, (_, index) => `S${String(index + 1).padStart(2, '0')}`)];
const weekdayRooms = {
  '2026-09-07': ['P01', 'P02', 'N01', 'N03', 'N05', 'N06', 'N09', 'S06'],
  '2026-09-08': ['P01', 'P02', 'N02', 'N04', 'N05', 'N07', 'S01', 'S04'],
  '2026-09-09': ['P01', 'P02', 'N01', 'N03', 'N08', 'N09', 'S03', 'S07'],
  '2026-09-10': ['P01', 'P02', 'N02', 'N04', 'N05', 'N06', 'S05', 'S08'],
  '2026-09-11': ['P01', 'P02', ...Array.from({ length: 10 }, (_, index) => `N${String(index + 1).padStart(2, '0')}`), 'S01', 'S03', 'S04', 'S06'],
};
const threeDimensionalTitles = new Set(['Tangled', 'WALL-E', 'The Avengers', 'Big Hero 6', 'Frozen', 'Moana', 'The Little Mermaid', 'Brave', 'The Lion King', 'Finding Nemo', 'Zootopia', 'Beauty and the Beast', 'Wreck-It Ralph', 'Inside Out', 'Monsters University', 'Up']);

function titleForOpeningSlot(roomCode, hour) {
  if (roomCode === 'P01') return hour === 10 || hour === 16 ? 'Tangled' : 'The Avengers';
  if (roomCode === 'P02') return hour === 10 || hour === 16 ? 'WALL-E' : 'Frozen';
  return fixedOpeningTitles[roomCode];
}

function screeningDetails(title, roomCode, date, hour) {
  const weekend = ['2026-09-05', '2026-09-06'].includes(date) || new Date(`${date}T12:00:00Z`).getUTCDay() % 6 === 0;
  const roomType = roomCode[0];
  const format = roomCode === 'P01' ? 'IMAX' : roomCode === 'P02' ? 'Dolby Atmos' : roomType === 'N' && weekend && hour === 14 && threeDimensionalTitles.has(title) ? '3D' : 'Standard';
  const language = roomType === 'P' ? 'English' : roomType === 'N' ? weekend && hour === 10 ? 'Bahasa Indonesia' : 'English' : (!weekend && hour === 16) || (weekend && [10, 14].includes(hour)) ? 'Bahasa Indonesia' : 'English';
  const unitPrice = roomCode === 'P01' ? 150000 : roomCode === 'P02' ? 135000 : roomType === 'N' ? 80000 : 60000;
  return { title, roomCode, startsAt: `${date}T${String(hour).padStart(2, '0')}:00`, format, language, unitPrice };
}

export function openingWeekScreenings() {
  const screenings = [];
  for (const date of ['2026-09-05', '2026-09-06']) {
    for (const roomCode of allRooms) for (const hour of [10, 14, 18]) screenings.push(screeningDetails(titleForOpeningSlot(roomCode, hour), roomCode, date, hour));
  }
  for (const [date, rooms] of Object.entries(weekdayRooms)) {
    for (const roomCode of rooms) for (const hour of [16, 20]) screenings.push(screeningDetails(titleForOpeningSlot(roomCode, hour), roomCode, date, hour));
  }
  if (screenings.length !== 228) throw new Error(`Expected 228 opening-week screenings, received ${screenings.length}.`);
  return screenings;
}

export function laterWaveLaunchScreenings() {
  const screenings = movies.filter((item) => item.anchorRoom).map((item) => screeningDetails(item.title, item.anchorRoom, item.runStartDate, 10));
  if (screenings.length !== 21) throw new Error(`Expected 21 launch screenings, received ${screenings.length}.`);
  return screenings;
}

export function programmeScreenings() {
  const screenings = [...openingWeekScreenings(), ...laterWaveLaunchScreenings()];
  if (screenings.length !== 249) throw new Error(`Expected 249 programme screenings, received ${screenings.length}.`);
  for (const screening of screenings) if (!movieByTitle.has(screening.title)) throw new Error(`Unknown movie ${screening.title}.`);
  return screenings;
}
