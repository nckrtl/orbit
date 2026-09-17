export const TerminalDemo = ({ demo }) => {
  const [phase, setPhase] = useState('idle');
  const [run, setRun] = useState(0);
  const element = useRef(null);
  const screen = useRef(null);
  const terminal = useRef(null);
  const typingDelay = 35;
  // A wait longer than this plays as this long; the recording's cadence is otherwise unchanged.
  const maxGap = 1.5;

  const theme = {
    background: '#0b0b0d', foreground: '#e8e6e0', cursor: '#e8e6e0', selectionBackground: '#3a3a40',
    black: '#3a3a40', red: '#f07178', green: '#8fd18f', yellow: '#e5c07b', blue: '#82aaff', magenta: '#c792ea', cyan: '#89ddff', white: '#e8e6e0',
    brightBlack: '#6f6d66', brightRed: '#f07178', brightGreen: '#8fd18f', brightYellow: '#e5c07b', brightBlue: '#82aaff', brightMagenta: '#c792ea', brightCyan: '#89ddff', brightWhite: '#f4f2ec',
  };

  // xterm.js is vendored in docs/vendor/xterm and loads as a global after the page becomes interactive.
  const mount = () => {
    if (terminal.current || !screen.current || typeof window === 'undefined' || !window.Terminal) {
      return Boolean(terminal.current);
    }
    // Show only the columns the recording used, at the page's code size. A wider recording never wrapped
    // inside those columns, so a terminal this wide replays it exactly; a very wide output scrolls sideways.
    const rows = Math.min(demo.rows, (demo.rows_used || demo.rows) + 4);
    const font = '"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace';
    const fontSize = 13;
    // Wide enough that neither the typed command nor any recorded line wraps; a wider terminal than the
    // recording is safe because no recorded line reached the recorded width. Wider than the page scrolls sideways.
    const cols = Math.max(demo.columns_used || demo.columns, demo.command.length + 3, 40);
    const instance = new window.Terminal({
      cols, rows, fontFamily: font,
      fontSize, lineHeight: 1, theme, cursorBlink: true, cursorStyle: 'bar', disableStdin: true, scrollback: 0, convertEol: false,
    });
    instance.open(screen.current);
    // The WebGL renderer draws box-drawing characters itself, so tree lines connect like they do in a terminal.
    try {
      if (window.WebglAddon) instance.loadAddon(new window.WebglAddon.WebglAddon());
    } catch (error) {
      // The DOM renderer stays in place when WebGL is unavailable.
    }
    terminal.current = instance;
    return true;
  };

  useEffect(() => {
    let tries = 0;
    const timer = setInterval(() => {
      tries += 1;
      if (mount() || tries > 100) clearInterval(timer);
    }, 50);
    return () => { clearInterval(timer); if (terminal.current) { terminal.current.dispose(); terminal.current = null; } };
  }, []);

  useEffect(() => {
    if (!element.current || run > 0) return undefined;
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) { observer.disconnect(); setRun(1); }
    }, { threshold: 0.4 });
    observer.observe(element.current);
    return () => observer.disconnect();
  }, [run]);

  useEffect(() => {
    if (run === 0) return undefined;
    let cancelled = false;
    const timers = [];
    const later = (ms, fn) => timers.push(setTimeout(() => { if (!cancelled) fn(); }, ms));
    const start = () => {
      const term = terminal.current;
      term.reset();
      setPhase('typing');
      term.write('\x1b[90m$\x1b[0m ');
      const command = demo.command;
      for (let i = 0; i < command.length; i += 1) {
        later((i + 1) * typingDelay, () => term.write(command[i]));
      }
      let at = command.length * typingDelay + 350;
      later(at, () => { term.write('\r\n'); setPhase('playing'); });
      demo.cast.forEach(([delta, text]) => {
        at += Math.min(delta, maxGap) * 1000;
        later(at, () => term.write(text));
      });
      later(at + 300, () => { term.write('\x1b[90m$\x1b[0m '); setPhase('done'); });
    };
    let tries = 0;
    const waiter = setInterval(() => {
      tries += 1;
      if (mount()) { clearInterval(waiter); start(); } else if (tries > 100) clearInterval(waiter);
    }, 50);
    return () => { cancelled = true; clearInterval(waiter); timers.forEach(clearTimeout); };
  }, [run]);

  return (
    <div className="orbit-demo not-prose" ref={element}>
      <div className="orbit-demo__bar">
        <span className="orbit-demo__dots"><i /><i /><i /></span>
        <span className="orbit-demo__title">{demo.command}</span>
        <button type="button" className="orbit-demo__replay" onClick={() => setRun(run + 1)} disabled={phase === 'typing' || phase === 'playing'}>
          {phase === 'done' ? 'Replay' : phase === 'idle' ? 'Play' : 'Playing'}
        </button>
      </div>
      <div className="orbit-demo__terminal" ref={screen} />
      <div className="orbit-demo__caption">
        Recorded from commit {demo.recorded.candidate.slice(0, 8)} on {demo.recorded.date}, exit status {demo.recorded.exit_code}, {Math.round(demo.recorded.duration_seconds)} s.
      </div>
    </div>
  );
};
