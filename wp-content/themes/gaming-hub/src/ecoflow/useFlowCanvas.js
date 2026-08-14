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

	if ( flowId === 'proToDelta' || flowId === 'proToLink' || flowId === 'linkToDelta' ) {
		if ( ! status.dual ) {
			return 0;
		}

		return Number( status.link_watts ) || linkWatts( status.pro );
	}

	if ( flowId === 'proToHome' || flowId === 'deltaToHome' || flowId === 'home' ) {
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
		const x = connection.align === 'from'
			? fromRect.cx
			: connection.align === 'to'
				? toRect.cx
				: ( fromRect.cx + toRect.cx ) / 2;
		from = { x, y: from.y };
		to = { x, y: to.y };
	}

	const inset = 6;
	const dx = to.x - from.x;
	const dy = to.y - from.y;
	const length = Math.hypot( dx, dy ) || 1;
	from = {
		x: from.x + ( dx / length ) * inset,
		y: from.y + ( dy / length ) * inset,
	};
	to = {
		x: to.x - ( dx / length ) * inset,
		y: to.y - ( dy / length ) * inset,
	};

	return {
		id: connection.id,
		from,
		to,
		color: connection.color,
		axis: connection.axis,
		showLabel: !! connection.showLabel,
		alwaysLabel: !! connection.alwaysLabel,
	};
}

function resolvePaths( mapEl, status ) {
	const connections = status?.dual ? FLOW_CONNECTIONS_DUAL : FLOW_CONNECTIONS_SINGLE;

	return connections
		.map( ( connection ) => resolveConnection( mapEl, connection ) )
		.filter( Boolean );
}

function drawArrow( ctx, from, to, color ) {
	const angle = Math.atan2( to.y - from.y, to.x - from.x );
	const size = 9;

	ctx.beginPath();
	ctx.moveTo( to.x, to.y );
	ctx.lineTo(
		to.x - size * Math.cos( angle - 0.45 ),
		to.y - size * Math.sin( angle - 0.45 )
	);
	ctx.lineTo(
		to.x - size * Math.cos( angle + 0.45 ),
		to.y - size * Math.sin( angle + 0.45 )
	);
	ctx.closePath();
	ctx.fillStyle = color;
	ctx.shadowColor = color;
	ctx.shadowBlur = 8;
	ctx.fill();
	ctx.shadowBlur = 0;
}

function drawWattsLabel( ctx, from, to, watts, color, axis ) {
	const text = `${ Math.round( watts ).toLocaleString() } W`;
	let mx = ( from.x + to.x ) / 2;
	let my = ( from.y + to.y ) / 2;

	if ( axis === 'vertical' ) {
		mx += 28;
	}

	ctx.font = '700 11px Inter, sans-serif';
	const width = ctx.measureText( text ).width;
	const padX = 7;
	const height = 18;
	const boxW = width + padX * 2;
	const boxH = height;
	const x = mx - boxW / 2;
	const y = my - boxH / 2;

	ctx.beginPath();
	if ( typeof ctx.roundRect === 'function' ) {
		ctx.roundRect( x, y, boxW, boxH, 4 );
	} else {
		ctx.rect( x, y, boxW, boxH );
	}
	ctx.fillStyle = 'rgba(8, 7, 15, 0.92)';
	ctx.fill();
	ctx.lineWidth = 1;
	ctx.strokeStyle = color;
	ctx.stroke();

	ctx.fillStyle = color;
	ctx.textAlign = 'center';
	ctx.textBaseline = 'middle';
	ctx.fillText( text, mx, my + 0.5 );
}

function drawPath( ctx, path, active, dashOffset, watts ) {
	const { from, to, color } = path;

	ctx.beginPath();
	ctx.moveTo( from.x, from.y );
	ctx.lineTo( to.x, to.y );
	ctx.lineCap = 'round';
	ctx.lineWidth = active ? 3.5 : 2;
	ctx.strokeStyle = active ? `${ color }73` : 'rgba(0, 245, 212, 0.14)';
	ctx.stroke();

	if ( ! active ) {
		drawArrow( ctx, from, to, 'rgba(0, 245, 212, 0.28)' );
		if ( path.alwaysLabel ) {
			drawWattsLabel( ctx, from, to, watts, color, path.axis );
		}
		return;
	}

	ctx.beginPath();
	ctx.moveTo( from.x, from.y );
	ctx.lineTo( to.x, to.y );
	ctx.setLineDash( [ 10, 14 ] );
	ctx.lineDashOffset = -dashOffset;
	ctx.lineWidth = 3;
	ctx.strokeStyle = color;
	ctx.globalAlpha = 0.75;
	ctx.stroke();
	ctx.setLineDash( [] );
	ctx.globalAlpha = 1;

	drawArrow( ctx, from, to, color );

	const pathLength = Math.hypot( to.x - from.x, to.y - from.y );
	if ( path.showLabel && ( path.alwaysLabel || ( watts >= FLOW_THRESHOLD && pathLength >= 48 ) ) ) {
		drawWattsLabel( ctx, from, to, watts, color, path.axis );
	}
}

function drawParticle( ctx, path, progress, color ) {
	const x = path.from.x + ( path.to.x - path.from.x ) * progress;
	const y = path.from.y + ( path.to.y - path.from.y ) * progress;

	ctx.beginPath();
	ctx.arc( x, y, 5, 0, Math.PI * 2 );
	ctx.fillStyle = color;
	ctx.shadowColor = color;
	ctx.shadowBlur = 12;
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

				drawPath( ctx, path, active, dashOffsetRef.current, watts );

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
