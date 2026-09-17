export const TerminalDemo = ({ demo }) => {
  const [rows, setRows] = useState({});
  const [typed, setTyped] = useState('');
  const [phase, setPhase] = useState('idle');
  const [run, setRun] = useState(0);
  const element = useRef(null);
  const typingDelay = 35;
  const maxGap = 1.5;
  // Play a long recording faster so a reader sees the whole command within about 20 seconds.
  const rate = Math.min(8, Math.max(1, Math.round(demo.recorded.duration_seconds / 20)));

  useEffect(() => {
    if (!element.current || run > 0) {
      return undefined;
    }
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        observer.disconnect();
        setRun(1);
      }
    }, { threshold: 0.4 });
    observer.observe(element.current);
    return () => observer.disconnect();
  }, [run]);

  useEffect(() => {
    if (run === 0) {
      return undefined;
    }
    let cancelled = false;
    const timers = [];
    const later = (ms, fn) => timers.push(setTimeout(() => { if (!cancelled) fn(); }, ms));

    setRows({});
    setTyped('');
    setPhase('typing');
    const command = demo.command;
    for (let i = 1; i <= command.length; i += 1) {
      later(i * typingDelay, () => setTyped(command.slice(0, i)));
    }
    let at = command.length * typingDelay + 300;
    let previous = 0;
    demo.frames.forEach((frame, index) => {
      at += (Math.min(frame.t - previous, maxGap) * 1000) / rate;
      previous = frame.t;
      later(at, () => {
        if (index === 0) setPhase('playing');
        setRows((current) => ({ ...current, ...frame.rows }));
      });
    });
    later(at + 400, () => setPhase('done'));
    return () => { cancelled = true; timers.forEach(clearTimeout); };
  }, [run, demo]);

  const lines = [];
  for (let row = 0; row < demo.rows; row += 1) {
    lines.push(rows[String(row)] || []);
  }
  while (lines.length > 0 && lines[lines.length - 1].length === 0) {
    lines.pop();
  }

  return (
    <div className="orbit-demo not-prose" ref={element}>
      <div className="orbit-demo__bar">
        <span className="orbit-demo__dots"><i /><i /><i /></span>
        <span className="orbit-demo__title">{demo.command}</span>
        <button type="button" className="orbit-demo__replay" onClick={() => setRun(run + 1)} disabled={phase === 'typing' || phase === 'playing'}>
          {phase === 'done' ? 'Replay' : phase === 'idle' ? 'Play' : 'Playing'}
        </button>
      </div>
      <pre className="orbit-demo__screen" style={{ width: `calc(${demo.columns}ch + 2rem)` }}>
        <span className="orbit-demo__prompt">$ </span>{typed}
        {phase === 'typing' ? <span className="orbit-demo__cursor" /> : null}
        {'\n'}
        {lines.map((segments, row) => (
          <span key={row} className="orbit-demo__line">
            {segments.map(([text, style], index) => (
              <span key={index} className={style ? style.split(' ').map((token) => `orbit-demo__${token}`).join(' ') : undefined}>{text}</span>
            ))}
            {'\n'}
          </span>
        ))}
        {phase === 'done' ? <span><span className="orbit-demo__prompt">$ </span><span className="orbit-demo__cursor" /></span> : null}
      </pre>
      <div className="orbit-demo__caption">
        Recorded from commit {demo.recorded.candidate.slice(0, 8)} on {demo.recorded.date}, exit status {demo.recorded.exit_code}, {Math.round(demo.recorded.duration_seconds)} s{rate > 1 ? ` played at ${rate}×` : ''}.
      </div>
    </div>
  );
};
