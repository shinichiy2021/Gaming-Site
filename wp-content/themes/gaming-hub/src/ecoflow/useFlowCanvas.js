import { useEffect, useRef } from 'react';
import {
	FLOW_CONNECTIONS_DUAL,
	FLOW_CONNECTIONS_SINGLE,
	FLOW_THRESHOLD,
	flowSpeed,
	homeOutput,
	linkWatts,
} from './constants';

function wattsForFlow( flowId, status ) {
	if ( ! status ) {
		return 0;
	}

	if ( flowId === 'solar' ) {
		return Number( status.solar_in ) || 0;
	}

	if ( flowId === 'grid' ) {
		return Number( status.grid_in ?? status.ac_in ) || 0;
	}

	if ( flowId === 'proToDelta' ) {
		if ( ! status.dual ) {
			return 0;
		}

		return Number( status.link_watts ) || linkWatts( status.pro, status.delta );
	}

	if ( flowId === 'deltaToHome' || flowId === 'home' ) {
		return homeOutput( status );
	}

	return 0;
}

function anchorRect( mapEl, anchorId ) {
	const el = mapEl.querySelector( `[data-flow-id="${ anchorId }"]` );
	if ( ! el ) {
		return null;
	}

	const mapRect = mapEl.getBoundingClientRect();
	const rect = el.getBoundingClientRect();

	return {
		left: rect.left - mapRect.left,
		right: rect.right - mapRect.left,
		top: rect.top - mapRect.top,
		bottom: rect.bottom - mapRect.top,
		cx: rect.left - mapRect.left + rect.width / 2,
		cy: rect.top - mapRect.top + rect.height / 2,
	};
}

function resolvePoint( rect, side ) {
	if ( ! rect ) {
		return null;
	}

	switch ( side ) {
		case 'left':
			return { x: rect.left, y: rect.cy };
		case 'right':
			return { x: rect.right, y: rect.cy };
		case 'top':
			return { x: rect.cx, y: rect.top };
		case 'bottom':
			return { x: rect.cx, y: rect.bottom };
		default:
			return { x: rect.cx, y: rect.cy };
	}
}

function resolveConnection( mapEl, connection ) {
	const fromRect = anchorRect( mapEl, connection.from.id );
	const toRect = anchorRect( mapEl, connection.to.id );

	if ( ! fromRect || ! toRect ) {
		return null;
	}

	let from = resolvePoint( fromRect, connection.from.side );
	let to = resolvePoint( toRect, connection.to.side );

	if ( connection.axis === 'horizontal' ) {
		const y = ( fromRect.cy + toRect.cy ) / 2;
		from = { x: from.x, y };
		to = { x: to.x, y };
	} else if ( connection.axis === 'vertical' ) {
		const x = ( fromRect.cx + toRect.cx ) / 2;
		from = { x, y: from.y };
		to = { x, y: to.y };
	}

	return {
		id: connection.id,
		from,
		to,
		color: connection.color,
	};
}

function resolvePaths( mapEl, status ) {
	const connections = status?.dual ? FLOW_CONNECTIONS_DUAL : FLOW_CONNECTIONS_SINGLE;

	return connections
		.map( ( connection ) => resolveConnection( mapEl, connection ) )
		.filter( Boolean );
}

function drawPath( ctx, path, active, dashOffset ) {
	const { from, to, color } = path;

	ctx.beginPath();
	ctx.moveTo( from.x, from.y );
	ctx.lineTo( to.x, to.y );
	ctx.lineCap = 'round';
	ctx.lineWidth = active ? 3 : 2;
	ctx.strokeStyle = active ? 'rgba(0, 200, 83, 0.35)' : 'rgba(0, 200, 83, 0.12)';
	ctx.stroke();

	if ( ! active ) {
		return;
	}

	ctx.beginPath();
	ctx.moveTo( from.x, from.y );
	ctx.lineTo( to.x, to.y );
	ctx.setLineDash( [ 8, 12 ] );
	ctx.lineDashOffset = -dashOffset;
	ctx.lineWidth = 2.5;
	ctx.strokeStyle = color;
	ctx.globalAlpha = 0.65;
	ctx.stroke();
	ctx.setLineDash( [] );
	ctx.globalAlpha = 1;
}

function drawParticle( ctx, path, progress, color ) {
	const x = path.from.x + ( path.to.x - path.from.x ) * progress;
	const y = path.from.y + ( path.to.y - path.from.y ) * progress;

	ctx.beginPath();
	ctx.arc( x, y, 4, 0, Math.PI * 2 );
	ctx.fillStyle = color;
	ctx.shadowColor = color;
	ctx.shadowBlur = 6;
	ctx.fill();
	ctx.shadowBlur = 0;
}

export function useFlowCanvas( canvasRef, mapRef, status ) {
	const particlesRef = useRef( [] );
	const dashOffsetRef = useRef( 0 );
	const lastTimeRef = useRef( 0 );

	useEffect( () => {
		const canvas = canvasRef.current;
		const mapEl = mapRef.current;

		if ( ! canvas || ! mapEl ) {
			return undefined;
		}

		const ctx = canvas.getContext( '2d', { alpha: true } );
		let rafId = 0;

		const resize = () => {
			const width = mapEl.clientWidth;
			const height = mapEl.clientHeight;
			const dpr = Math.min( window.devicePixelRatio || 1, 2 );

			canvas.width = Math.round( width * dpr );
			canvas.height = Math.round( height * dpr );
			canvas.style.width = `${ width }px`;
			canvas.style.height = `${ height }px`;
			ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
		};

		const observer = typeof ResizeObserver !== 'undefined'
			? new ResizeObserver( resize )
			: null;

		resize();
		window.addEventListener( 'resize', resize );
		observer?.observe( mapEl );

		const frame = ( time ) => {
			rafId = window.requestAnimationFrame( frame );

			if ( document.hidden ) {
				return;
			}

			const delta = lastTimeRef.current ? Math.min( ( time - lastTimeRef.current ) / 1000, 0.05 ) : 0;
			lastTimeRef.current = time;
			dashOffsetRef.current += delta * 24;

			const width = mapEl.clientWidth;
			const height = mapEl.clientHeight;
			const paths = resolvePaths( mapEl, status );

			ctx.clearRect( 0, 0, width, height );

			const nextParticles = [];

			paths.forEach( ( path ) => {
				const watts = wattsForFlow( path.id, status );
				const active = watts >= FLOW_THRESHOLD;

				drawPath( ctx, path, active, dashOffsetRef.current );

				if ( ! active ) {
					return;
				}

				let particles = particlesRef.current.filter( ( particle ) => particle.id === path.id );
				if ( particles.length < 2 ) {
					particles = [
						{ id: path.id, progress: 0 },
						{ id: path.id, progress: 0.45 },
					];
				}

				const speed = flowSpeed( watts );
				particles.forEach( ( particle ) => {
					let progress = particle.progress + speed * delta;
					while ( progress > 1 ) {
						progress -= 1;
					}

					drawParticle( ctx, path, progress, path.color );
					nextParticles.push( { id: path.id, progress } );
				} );
			} );

			particlesRef.current = nextParticles;
		};

		rafId = window.requestAnimationFrame( frame );

		return () => {
			window.cancelAnimationFrame( rafId );
			window.removeEventListener( 'resize', resize );
			observer?.disconnect();
			lastTimeRef.current = 0;
			particlesRef.current = [];
		};
	}, [ canvasRef, mapRef, status ] );
}
